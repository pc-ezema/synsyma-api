<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\User\Support\StripeGateway;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/** Public endpoint Stripe calls. Authenticity comes from the signature, not from a login. */
class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeGateway $stripe): JsonResponse
    {
        $secret = config('stripe.webhook_secret');
        if (! $secret) {
            Log::error('Stripe webhook hit but STRIPE_WEBHOOK_SECRET is not set.');

            return response()->json(['message' => 'Webhook not configured.'], 500);
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (\UnexpectedValueException | SignatureVerificationException $e) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        try {
            $stripe->handleEvent($event);
        } catch (\Throwable $e) {
            // 500 makes Stripe retry later.
            Log::error('Stripe webhook ' . $event->type . ' failed: ' . $e->getMessage());

            return response()->json(['message' => 'Handler failed.'], 500);
        }

        return response()->json(['message' => 'Received.']);
    }
}