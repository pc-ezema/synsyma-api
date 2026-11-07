<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\Booking;
use Modules\Admin\Models\Contractor;
use Modules\User\Support\CancellationPolicy;
use Modules\User\Support\StripeGateway;

/** Admin: see every booking, assign a contractor, move it along. */
class AdminBookingController extends Controller
{
    public function __construct(private readonly StripeGateway $stripe)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $q = Booking::with(['user', 'contractor', 'service'])->orderByDesc('starts_at');

        if (in_array($request->query('status'), Booking::STATUSES, true)) {
            $q->where('status', $request->query('status'));
        }
        if ($s = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('reference', 'like', "%{$s}%")
                ->orWhere('service_name', 'like', "%{$s}%")
                ->orWhere('city', 'like', "%{$s}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$s}%")));
        }

        $page = $q->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Booking $b) => $b->toArrayFlat(false, true))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function summary(): JsonResponse
    {
        $counts = Booking::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $out = ['all' => (int) $counts->sum()];
        foreach (Booking::STATUSES as $s) {
            $out[$s] = (int) ($counts[$s] ?? 0);
        }

        return response()->json(['data' => $out]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->load($id)->toArrayFlat(true, true)]);
    }

    /** Approved contractors who offer this booking's category, those covering the address first. */
    public function contractorOptions(string $id): JsonResponse
    {
        $booking = Booking::with('service')->findOrFail($id);
        $categoryId = $booking->service?->category_id;

        $rows = Contractor::with('areas')->where('status', 'approved')
            ->whereHas('categories', fn ($c) => $c->where('service_categories.id', $categoryId))
            ->orderBy('name')->get()
            ->map(function (Contractor $c) use ($booking) {
                $covers = $c->areas->contains(fn ($a) => $a->country === $booking->country
                    && strcasecmp($a->region, $booking->region) === 0
                    && ($a->city === null || strcasecmp($a->city, $booking->city) === 0));

                return [
                    'id' => $c->id, 'name' => $c->name, 'type' => $c->type,
                    'phone' => $c->phone, 'email' => $c->email, 'covers_area' => $covers,
                ];
            })
            ->sortByDesc('covers_area')->values();

        return response()->json(['data' => $rows]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);
        $data = $request->validate(['contractor_id' => ['required', 'uuid']]);

        if (! in_array($booking->status, ['confirmed', 'assigned'], true)) {
            throw ValidationException::withMessages(['contractor_id' => 'Only a confirmed (paid) booking can be assigned.']);
        }
        $contractor = Contractor::where('status', 'approved')->find($data['contractor_id']);
        if (! $contractor) {
            throw ValidationException::withMessages(['contractor_id' => 'Choose an approved contractor.']);
        }

        $from = $booking->status;
        $booking->fill(['contractor_id' => $contractor->id, 'status' => 'assigned', 'assigned_at' => now()])->save();
        $booking->log('assigned', 'admin', $from, 'assigned', $contractor->name);

        return response()->json(['message' => "Assigned to {$contractor->name}.", 'data' => $this->load($id)->toArrayFlat(true, true)]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);
        $data = $request->validate([
            'status' => ['required', Rule::in(Booking::STATUSES)],
            'note' => ['nullable', 'string', 'max:300'],
            // Only used when cancelling: none | policy (what the customer would have got) | full
            'refund' => ['nullable', Rule::in(['none', 'policy', 'full'])],
        ]);
        $to = $data['status'];
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        if ($to === 'assigned') {
            throw ValidationException::withMessages(['status' => 'Use Assign contractor to assign a booking.']);
        }
        if (! in_array($to, Booking::TRANSITIONS[$booking->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A {$booking->status} booking cannot be set to {$to}."]);
        }
        if ($to === 'cancelled' && ! $note) {
            throw ValidationException::withMessages(['note' => 'Give a short reason for cancelling.']);
        }

        $refundNote = '';
        if ($to === 'cancelled' && ($data['refund'] ?? 'none') !== 'none') {
            $amount = $data['refund'] === 'full' ? $this->stripe->refundableTotal($booking) : CancellationPolicy::quote($booking)['refund'];
            if ($amount > 0) {
                try {
                    $done = $this->stripe->refundBooking($booking, $amount);
                } catch (\Throwable $e) {
                    throw ValidationException::withMessages(['refund' => 'The refund could not be processed: ' . $e->getMessage()]);
                }
                $refundNote = $done > 0 ? ' Refunded ' . $booking->money($done) . '.' : '';
            }
        }

        $from = $booking->status;
        $booking->status = $to;
        if ($to === 'confirmed') {
            $booking->contractor_id = null;   // back from "assigned": the contractor is released
            $booking->assigned_at = null;
        }
        if ($to === 'completed') {
            $booking->completed_at = now();
        }
        if ($to === 'cancelled') {
            $booking->cancelled_at = now();
        }
        $booking->save();
        $booking->log('status', 'admin', $from, $to, trim(($note ?? '') . $refundNote) ?: null);

        return response()->json(['message' => 'Booking updated.', 'data' => $this->load($id)->toArrayFlat(true, true)]);
    }

    /** A refund on its own (for example after a completed job). */
    public function refund(Request $request, string $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['required', 'string', 'max:300'],
        ]);

        $left = $this->stripe->refundableTotal($booking);
        if ($left <= 0) {
            throw ValidationException::withMessages(['amount' => 'There is no paid amount to refund on this booking.']);
        }
        if ((float) $data['amount'] > $left) {
            throw ValidationException::withMessages(['amount' => 'The most you can refund is ' . $booking->money($left) . '.']);
        }

        try {
            $done = $this->stripe->refundBooking($booking, (float) $data['amount']);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['amount' => 'The refund could not be processed: ' . $e->getMessage()]);
        }

        $from = $booking->status;
        if ($booking->status === 'completed' && $this->stripe->refundableTotal($booking) <= 0) {
            $booking->update(['status' => 'refunded']);
        }
        $booking->log('refund', 'admin', $from, $booking->status !== $from ? $booking->status : null, 'Refunded ' . $booking->money($done) . '. ' . trim($data['note']));

        return response()->json(['message' => 'Refunded ' . $booking->money($done) . '.', 'data' => $this->load($id)->toArrayFlat(true, true)]);
    }

    /** Admin moves the job. Unlike the customer, no free-reschedule limit applies, and the contractor stays. */
    public function reschedule(Request $request, string $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);
        $data = $request->validate([
            'starts_at_local' => ['required', 'date_format:Y-m-d\TH:i'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        if (! in_array($booking->status, ['pending_payment', 'confirmed', 'assigned'], true)) {
            throw ValidationException::withMessages(['starts_at_local' => "A {$booking->status} booking cannot be rescheduled."]);
        }
        $local = Carbon::createFromFormat('Y-m-d\TH:i', $data['starts_at_local'], $booking->timezone);
        if ($local->isPast()) {
            throw ValidationException::withMessages(['starts_at_local' => 'Choose a time in the future.']);
        }

        $old = $booking->starts_at->copy()->setTimezone($booking->timezone);
        $booking->update(['starts_at' => $local->copy()->utc()]);
        $note = trim((string) ($data['note'] ?? ''));
        $booking->log('rescheduled', 'admin', null, null, 'Moved from ' . $old->format('D j M Y, g:ia') . ' to ' . $local->format('D j M Y, g:ia') . '.' . ($note !== '' ? ' ' . $note : ''));

        return response()->json(['message' => 'Booking rescheduled.', 'data' => $this->load($id)->toArrayFlat(true, true)]);
    }

    public function updateNotes(Request $request, string $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);
        $data = $request->validate(['admin_notes' => ['nullable', 'string', 'max:2000']]);
        $booking->update(['admin_notes' => trim((string) ($data['admin_notes'] ?? '')) ?: null]);

        return response()->json(['message' => 'Notes saved.', 'data' => $this->load($id)->toArrayFlat(true, true)]);
    }

    private function load(string $id): Booking
    {
        return Booking::with(['user', 'contractor', 'service', 'events', 'payments'])->findOrFail($id);
    }
}