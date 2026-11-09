<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\Booking;
use Modules\Admin\Support\Quotes;

/** Admin: price a quote request, revise it, or take it back. Guarded with can:bookings.manage. */
class AdminQuoteController extends Controller
{
    /** Send (or revise) the price. The customer then accepts and pays. */
    public function send(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:' . (config('stripe.minimum_cents', 50) / 100), 'max:' . Quotes::MAX_PRICE],
            'valid_days' => ['required', 'integer', 'min:1', 'max:60'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [
            'price.required' => 'Enter the price for this job.',
            'price.min' => 'The price must be at least $' . number_format(config('stripe.minimum_cents', 50) / 100, 2) . '.',
            'valid_days.required' => 'Choose how long the quote stays open.',
        ]);

        DB::transaction(function () use ($id, $data, $request) {
            $b = Booking::lockForUpdate()->findOrFail($id);
            $this->mustBeQuote($b);

            if (! in_array($b->status, ['quote_requested', 'quote_sent'], true)) {
                throw ValidationException::withMessages(['price' => 'Only an open quote request can be priced.']);
            }
            if ($b->starts_at->isPast()) {
                throw ValidationException::withMessages(['price' => 'The date the customer asked for has already passed. Cancel this request or ask them to submit a new one.']);
            }

            $price = round((float) $data['price'], 2);
            $revising = $b->status === 'quote_sent';
            $from = $b->status;

            $b->forceFill([
                'status' => 'quote_sent',
                'subtotal' => $price, 'discount_total' => 0, 'tax_total' => 0, 'total' => $price,
                'quote_sent_at' => now(),
                'quote_expires_at' => Quotes::expiryFor($b, (int) $data['valid_days']),
                'quote_message' => trim((string) ($data['message'] ?? '')) ?: null,
            ])->save();

            $b->log($revising ? 'quote_revised' : 'quote_sent', 'admin', $from, 'quote_sent',
                ($revising ? 'Quote revised to ' : 'Quote sent: ') . $b->money($price) . ', open for ' . (int) $data['valid_days'] . ' days.');
        });

        return $this->done($id, 'Quote sent.');
    }

    /** Take a sent quote back, for example if the price was wrong. The request stays open. */
    public function withdraw(Request $request, string $id): JsonResponse
    {
        DB::transaction(function () use ($id) {
            $b = Booking::lockForUpdate()->findOrFail($id);
            $this->mustBeQuote($b);

            if ($b->status !== 'quote_sent') {
                throw ValidationException::withMessages(['quote' => 'There is no sent quote to withdraw.']);
            }
            $b->forceFill([
                'status' => 'quote_requested',
                'subtotal' => 0, 'total' => 0,
                'quote_sent_at' => null, 'quote_expires_at' => null, 'quote_message' => null,
            ])->save();
            $b->log('quote_withdrawn', 'admin', 'quote_sent', 'quote_requested', 'Quote withdrawn.');
        });

        return $this->done($id, 'Quote withdrawn.');
    }

    private function mustBeQuote(Booking $b): void
    {
        if ($b->price_type !== 'quote') {
            throw ValidationException::withMessages(['quote' => 'This booking is not priced by quote.']);
        }
    }

    private function done(string $id, string $message): JsonResponse
    {
        $b = Booking::with(['user', 'contractor', 'service', 'events', 'payments'])->findOrFail($id);

        return response()->json(['message' => $message, 'data' => $b->toArrayFlat(true, true)]);
    }
}