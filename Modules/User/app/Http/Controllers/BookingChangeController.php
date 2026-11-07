<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\Booking;
use Modules\User\Support\BookingChanges;
use Modules\User\Support\CancellationPolicy;
use Modules\User\Support\Scheduling;
use Modules\User\Support\StripeGateway;

/** The customer changing their own booking: cancel, reschedule, and what is allowed right now. */
class BookingChangeController extends Controller
{
    public function __construct(private readonly StripeGateway $stripe)
    {
    }

    /** What the booking screen may offer: reschedule and extra hours. */
    public function options(Request $request, string $id): JsonResponse
    {
        $b = $this->mine($request, $id);

        return response()->json(['data' => [
            'reschedule' => BookingChanges::rescheduleRules($b),
            'extra_hours' => BookingChanges::extraHoursRules($b),
        ]]);
    }

    public function cancelPreview(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => CancellationPolicy::quote($this->mine($request, $id))]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $reason = trim((string) ($data['reason'] ?? '')) ?: null;
        $this->mine($request, $id);

        $result = DB::transaction(function () use ($id, $request, $reason) {
            $booking = Booking::lockForUpdate()->where('user_id', $request->user()->id)->findOrFail($id);
            $q = CancellationPolicy::quote($booking);

            if (! $q['allowed']) {
                throw ValidationException::withMessages(['booking' => [$q['message']]]);
            }

            $refunded = 0.0;
            if ($q['refund'] > 0) {
                try {
                    $refunded = $this->stripe->refundBooking($booking, $q['refund']);
                } catch (\Throwable $e) {
                    Log::error('Cancel refund failed for ' . $booking->id . ': ' . $e->getMessage());
                    throw ValidationException::withMessages(['booking' => ['We could not process your refund right now. Please try again in a moment.']]);
                }
            }

            $from = $booking->status;
            $booking->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $note = 'Cancelled by the customer.';
            if ($q['paid'] > 0) {
                $note .= $q['fee'] > 0 ? ' Fee ' . $booking->money($q['fee']) . '.' : '';
                $note .= ' Refunded ' . $booking->money($refunded) . '.';
            }
            if ($reason) {
                $note .= ' Reason: ' . $reason;
            }
            $booking->log('cancelled', 'customer', $from, 'cancelled', $note);

            return ['refunded' => $refunded];
        });

        $fresh = Booking::with(['service', 'contractor', 'events', 'payments'])->findOrFail($id);

        return response()->json([
            'message' => $result['refunded'] > 0 ? 'Booking cancelled. Your refund is on its way.' : 'Booking cancelled.',
            'data' => $fresh->toArrayFlat(true),
        ]);
    }

    /** Free reschedules, within the policy. A booked contractor is released, so the team re-assigns. */
    public function reschedule(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['starts_at' => ['required', 'date']]);
        $this->mine($request, $id);

        DB::transaction(function () use ($id, $request, $data) {
            $b = Booking::lockForUpdate()->where('user_id', $request->user()->id)->findOrFail($id);

            $rules = BookingChanges::rescheduleRules($b);
            if (! $rules['allowed']) {
                throw ValidationException::withMessages(['booking' => [$rules['message']]]);
            }

            $start = Scheduling::checkStart($data['starts_at'], $b->timezone);
            if ($start->equalTo($b->starts_at)) {
                throw ValidationException::withMessages(['starts_at' => ['That is the time you already have. Choose a different one.']]);
            }

            $from = $b->status;
            $old = $b->starts_at->copy()->setTimezone($b->timezone);
            $changes = ['starts_at' => $start->copy()->utc(), 'reschedule_count' => (int) $b->reschedule_count + 1];
            $released = $b->status === 'assigned';
            if ($released) {
                $changes += ['status' => 'confirmed', 'contractor_id' => null, 'assigned_at' => null];
            }
            $b->update($changes);

            $b->log(
                'rescheduled', 'customer', $from, $released ? 'confirmed' : null,
                'Moved from ' . $old->format('D j M Y, g:ia') . ' to ' . $start->format('D j M Y, g:ia') . '.' . ($released ? ' The contractor was released and the booking needs a new one.' : '')
            );
        });

        $fresh = Booking::with(['service', 'contractor', 'events', 'payments'])->findOrFail($id);

        return response()->json(['message' => 'Your booking was rescheduled.', 'data' => $fresh->toArrayFlat(true)]);
    }

    private function mine(Request $request, string $id): Booking
    {
        return Booking::where('user_id', $request->user()->id)->with('payments')->findOrFail($id);
    }
}