<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\Booking;
use Modules\Admin\Support\Quotes;

/** The customer answering a quote. Accepting turns it into an unpaid booking, which they then pay as usual. */
class QuoteController extends Controller
{
    public function accept(Request $request, string $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id) {
            $b = Booking::lockForUpdate()->where('user_id', $request->user()->id)->findOrFail($id);

            $state = Quotes::state($b);
            if ($state === 'expired') {
                throw ValidationException::withMessages(['booking' => ['This quote has expired. Please ask us for a new one.']]);
            }
            if ($state !== 'sent') {
                throw ValidationException::withMessages(['booking' => ['This quote can no longer be accepted.']]);
            }
            if ($b->starts_at->lt(now()->addHour())) {
                throw ValidationException::withMessages(['booking' => ['The date you asked for is too close or has passed. Please contact support to agree a new date.']]);
            }

            $b->forceFill(['status' => 'pending_payment', 'quote_accepted_at' => now()])->save();
            $b->log('quote_accepted', 'customer', 'quote_sent', 'pending_payment', 'Accepted the quote of ' . $b->money($b->total) . '.');
        });

        return $this->done($request, $id, 'Quote accepted. You can pay now.');
    }

    public function decline(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $reason = trim((string) ($data['reason'] ?? '')) ?: null;

        DB::transaction(function () use ($request, $id, $reason) {
            $b = Booking::lockForUpdate()->where('user_id', $request->user()->id)->findOrFail($id);

            if (! in_array(Quotes::state($b), ['requested', 'sent', 'expired'], true) || ! in_array($b->status, ['quote_requested', 'quote_sent'], true)) {
                throw ValidationException::withMessages(['booking' => ['This quote is already closed.']]);
            }
            $from = $b->status;
            $b->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
            $b->log('quote_declined', 'customer', $from, 'cancelled', $from === 'quote_sent' ? 'Declined the quote.' . ($reason ? ' ' . $reason : '') : 'Cancelled the request.' . ($reason ? ' ' . $reason : ''));
        });

        return $this->done($request, $id, 'Done.');
    }

    private function done(Request $request, string $id, string $message): JsonResponse
    {
        $b = Booking::where('user_id', $request->user()->id)->with(['service', 'contractor', 'events', 'payments'])->findOrFail($id);

        return response()->json(['message' => $message, 'data' => $b->toArrayFlat(true)]);
    }
}