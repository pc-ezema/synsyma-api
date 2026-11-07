<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\Booking;
use Modules\User\Support\BookingChanges;
use Modules\User\Support\PayPalGateway;
use Modules\User\Support\StripeGateway;

/** Paying for the signed-in customer's own booking, by card (Stripe) or PayPal. */
class PaymentController extends Controller
{
    public function __construct(private readonly StripeGateway $stripe, private readonly PayPalGateway $paypal)
    {
    }

    /** Which ways to pay are switched on. */
    public function methods(): JsonResponse
    {
        $m = [];
        if (config('stripe.secret')) {
            $m[] = ['id' => 'card', 'label' => 'Card'];
        }
        if ($this->paypal->enabled()) {
            $m[] = ['id' => 'paypal', 'label' => 'PayPal'];
        }

        return response()->json(['message' => 'OK', 'data' => $m]);
    }

    /**
     * provider=stripe: mode sheet (phone payment sheet) or checkout (web, hosted Stripe page)
     * provider=paypal: mode redirect (PayPal's page; the web app also sends return_url)
     */
    public function start(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', 'in:stripe,paypal'],
            'mode' => ['required', 'in:sheet,checkout,redirect'],
            'extra_hours' => ['nullable', 'integer', 'min:1', 'max:12'], // set: pay for extra hours instead of the booking
            'return_url' => ['nullable', 'string', 'max:500'],
        ]);
        $provider = $data['provider'] ?? 'stripe';

        $user = $request->user();
        $booking = Booking::where('user_id', $user->id)->findOrFail($id);

        // If it was paid a moment ago but we have not heard yet, settle that first.
        $this->stripe->syncBooking($booking);
        $booking = $booking->fresh();

        $charge = null;
        if (! empty($data['extra_hours'])) {
            $charge = BookingChanges::extraCharge($booking, (int) $data['extra_hours']); // throws if not allowed
        } elseif ($why = $this->stripe->blocker($booking)) {
            throw ValidationException::withMessages(['booking' => [$why]]);
        }

        if ($provider === 'paypal') {
            if (! $this->paypal->enabled()) {
                throw ValidationException::withMessages(['booking' => ['PayPal is not available right now.']]);
            }
            $payload = $this->paypal->checkoutFor($booking, $user, $this->allowedReturn($data['return_url'] ?? null, false), $charge);
        } elseif ($data['mode'] === 'checkout') {
            $payload = $this->stripe->checkoutFor($booking, $user, $this->allowedReturn($data['return_url'] ?? null, true), $charge);
        } else {
            $payload = $this->stripe->sheetFor($booking, $user, $charge);
        }

        return response()->json(['message' => 'Payment ready.', 'data' => $payload]);
    }

    /** The app calls this after paying, so the screen is right even before the webhook lands. */
    public function sync(Request $request, string $id): JsonResponse
    {
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($id);
        $this->stripe->syncBooking($booking);

        $booking = $booking->fresh(['service', 'contractor', 'events', 'payments']);

        return response()->json(['message' => 'Synced.', 'data' => $booking->toArrayFlat(true)]);
    }

    /** Only our own app addresses are accepted as a place to send the customer back to. */
    private function allowedReturn(?string $url, bool $required): ?string
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
        if ($required) {
            throw ValidationException::withMessages(['return_url' => ['Web payments are not set up for this address.']]);
        }

        return null; // phone app: PayPal shows a "return to the app" page instead
    }
}