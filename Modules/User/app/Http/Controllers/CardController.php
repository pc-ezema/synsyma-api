<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Support\StripeGateway;

/**
 * The signed-in customer's saved cards. The cards live at Stripe: we never store a card number,
 * and we only ask Stripe for the brand, last 4 digits and expiry when the screen is opened.
 */
class CardController extends Controller
{
    public function __construct(private readonly StripeGateway $stripe)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $customer = $request->user()->stripe_customer_id;
        if (! $customer || ! config('stripe.secret')) {
            return response()->json(['data' => []]);
        }

        try {
            $res = $this->stripe->api('GET', '/payment_methods', ['customer' => $customer, 'type' => 'card', 'limit' => 20]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'We could not load your cards right now. Please try again.'], 502);
        }

        $y = (int) now()->year;
        $m = (int) now()->month;

        return response()->json(['data' => collect($res->data ?? [])->map(function ($pm) use ($y, $m) {
            $c = $pm->card ?? null;
            $ey = (int) ($c->exp_year ?? 0);
            $em = (int) ($c->exp_month ?? 0);

            return [
                'id' => $pm->id,
                'brand' => $c->brand ?? 'card',
                'last4' => $c->last4 ?? '••••',
                'exp_month' => $em,
                'exp_year' => $ey,
                'expired' => $ey > 0 && ($ey < $y || ($ey === $y && $em < $m)),
            ];
        })->values()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $customer = $request->user()->stripe_customer_id;
        if (! $customer || ! preg_match('/^pm_[A-Za-z0-9]+$/', $id)) {
            return response()->json(['message' => 'That card was not found.'], 404);
        }

        try {
            $pm = $this->stripe->api('GET', '/payment_methods/' . $id);
            // Only ever remove a card that belongs to this customer.
            if (($pm->customer ?? null) !== $customer) {
                return response()->json(['message' => 'That card was not found.'], 404);
            }
            $this->stripe->api('POST', '/payment_methods/' . $id . '/detach');
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'The card could not be removed. Please try again.'], 502);
        }

        return response()->json(['message' => 'Card removed.', 'data' => []]);
    }
}