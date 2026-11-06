<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\Booking;
use Modules\User\Support\StripeGateway;

/** Paying for the signed-in customer's own booking. */
class PaymentController extends Controller
{
    public function __construct(private readonly StripeGateway $stripe)
    {
    }

    /** mode=sheet (mobile payment sheet) or mode=checkout (web: hosted Stripe page). */
    public function start(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:sheet,checkout'],
            'return_url' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $booking = Booking::where('user_id', $user->id)->findOrFail($id);

        // If it was paid a moment ago but we have not heard yet, settle that first.
        $this->stripe->syncBooking($booking);
        $booking = $booking->fresh();

        if ($why = $this->stripe->blocker($booking)) {
            throw ValidationException::withMessages(['booking' => [$why]]);
        }

        if ($data['mode'] === 'checkout') {
            $url = $this->returnUrl($data['return_url'] ?? null);
            $payload = $this->stripe->checkoutFor($booking, $user, $url);
        } else {
            $payload = $this->stripe->sheetFor($booking, $user);
        }

        return response()->json(['message' => 'Payment ready.', 'data' => $payload]);
    }

    /** The app calls this after the payment sheet or the checkout page, so the screen is right even before the webhook lands. */
    public function sync(Request $request, string $id): JsonResponse
    {
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($id);
        $this->stripe->syncBooking($booking);

        $booking = $booking->fresh(['service', 'contractor', 'events', 'payments']);

        return response()->json(['message' => 'Synced.', 'data' => $booking->toArrayFlat(true)]);
    }

    private function returnUrl(?string $url): string
    {
        if ($url) {
            $host = parse_url($url, PHP_URL_HOST);
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if ($host && in_array($scheme, ['http', 'https'], true) && in_array($host, config('stripe.return_hosts', []), true)) {
                return $url;
            }
        }
        if ($fallback = config('stripe.return_url')) {
            return $fallback;
        }

        throw ValidationException::withMessages(['return_url' => ['Web payments are not set up for this address.']]);
    }
}