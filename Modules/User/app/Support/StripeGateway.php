<?php

namespace Modules\User\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\User\Models\Booking;
use Modules\User\Models\Payment;
use RuntimeException;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/** Everything that talks to Stripe. The webhook is the source of truth; the app's "sync" is a backup. */
class StripeGateway
{
    public function client(): StripeClient
    {
        $key = config('stripe.secret');
        if (! $key) {
            throw new RuntimeException('Stripe is not configured. Set STRIPE_SECRET in the API .env.');
        }

        return new StripeClient($key);
    }

    public function cents(Booking $b): int
    {
        return (int) round(((float) $b->total) * 100);
    }

    /** Why this booking cannot be paid right now, or null if it can. */
    public function blocker(Booking $b): ?string
    {
        if ($b->price_type === 'quote') {
            return 'This service is priced by quote.';
        }
        if ($b->status !== 'pending_payment') {
            return $b->status === 'cancelled' ? 'This booking was cancelled.' : 'This booking is already paid.';
        }
        if (strtoupper((string) $b->currency) !== 'USD') {
            return 'Only USD payments are supported.';
        }
        if ($this->cents($b) < config('stripe.minimum_cents')) {
            return 'This booking total is too small to charge.';
        }

        return null;
    }

    public function customerFor($user, Booking $b): string
    {
        if (! empty($user->stripe_customer_id)) {
            return $user->stripe_customer_id;
        }
        $c = $this->client()->customers->create([
            'email' => $user->email ?? null,
            'name' => $b->customerName(),
            'metadata' => ['user_id' => (string) $user->id],
        ]);
        $user->forceFill(['stripe_customer_id' => $c->id])->save();

        return $c->id;
    }

    /** Mobile payment sheet: a PaymentIntent (reused if the same one is still open). */
    public function sheetFor(Booking $b, $user): array
    {
        $stripe = $this->client();
        $customer = $this->customerFor($user, $b);
        $cents = $this->cents($b);

        $payment = null;
        $intent = null;

        $open = Payment::where('booking_id', $b->id)->where('status', 'requires_payment')
            ->whereNotNull('stripe_payment_intent_id')->latest()->first();
        if ($open && (int) round(((float) $open->amount) * 100) === $cents) {
            $pi = $stripe->paymentIntents->retrieve($open->stripe_payment_intent_id);
            if (in_array($pi->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true) && $pi->amount === $cents) {
                $payment = $open;
                $intent = $pi;
            }
        }

        if (! $intent) {
            $intent = $stripe->paymentIntents->create([
                'amount' => $cents,
                'currency' => config('stripe.currency'),
                'customer' => $customer,
                'description' => $b->service_name . ' · ' . $b->reference,
                'automatic_payment_methods' => ['enabled' => true],
                'setup_future_usage' => 'on_session', // lets the customer save the card
                'receipt_email' => $user->email ?? null,
                'metadata' => ['booking_id' => $b->id, 'reference' => $b->reference, 'user_id' => (string) $user->id],
            ], ['idempotency_key' => 'pi-' . $b->id . '-' . $cents . '-' . now()->timestamp]);

            $payment = Payment::create([
                'booking_id' => $b->id, 'user_id' => $user->id, 'amount' => $b->total, 'currency' => 'USD',
                'status' => 'requires_payment', 'stripe_payment_intent_id' => $intent->id,
            ]);
        }

        $ephemeral = null;
        try {
            $ephemeral = $stripe->ephemeralKeys->create(['customer' => $customer], ['stripe_version' => config('stripe.ephemeral_version')])->secret;
        } catch (\Throwable $e) {
            Log::warning('Stripe ephemeral key failed (saved cards disabled for this payment): ' . $e->getMessage());
        }

        return [
            'mode' => 'sheet',
            'payment_id' => $payment->id,
            'client_secret' => $intent->client_secret,
            'customer_id' => $ephemeral ? $customer : null,
            'ephemeral_key' => $ephemeral,
            'publishable_key' => config('stripe.publishable'),
            'amount' => $b->total,
            'currency' => 'USD',
        ];
    }

    /** Web build: a hosted Stripe Checkout page. */
    public function checkoutFor(Booking $b, $user, string $returnUrl): array
    {
        $stripe = $this->client();
        $customer = $this->customerFor($user, $b);
        $sep = str_contains($returnUrl, '?') ? '&' : '?';

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $customer,
            'client_reference_id' => $b->id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => config('stripe.currency'),
                    'unit_amount' => $this->cents($b),
                    'product_data' => ['name' => $b->service_name, 'description' => 'Booking ' . $b->reference],
                ],
            ]],
            'payment_intent_data' => [
                'setup_future_usage' => 'on_session',
                'description' => $b->service_name . ' · ' . $b->reference,
                'metadata' => ['booking_id' => $b->id, 'reference' => $b->reference, 'user_id' => (string) $user->id],
            ],
            'metadata' => ['booking_id' => $b->id],
            'success_url' => $returnUrl . $sep . 'paid=1',
            'cancel_url' => $returnUrl . $sep . 'paid=0',
        ]);

        Payment::create([
            'booking_id' => $b->id, 'user_id' => $user->id, 'amount' => $b->total, 'currency' => 'USD',
            'status' => 'requires_payment', 'stripe_checkout_session_id' => $session->id,
        ]);

        return ['mode' => 'checkout', 'url' => $session->url];
    }

    /** Backup for when the webhook has not arrived (or is not running locally): ask Stripe directly. */
    public function syncBooking(Booking $b): void
    {
        if ($b->status !== 'pending_payment') {
            return;
        }
        $stripe = $this->client();

        $open = Payment::where('booking_id', $b->id)->where('status', 'requires_payment')->latest()->get();
        foreach ($open as $payment) {
            try {
                if ($payment->stripe_payment_intent_id) {
                    $this->applyIntent($stripe->paymentIntents->retrieve($payment->stripe_payment_intent_id));
                } elseif ($payment->stripe_checkout_session_id) {
                    $s = $stripe->checkout->sessions->retrieve($payment->stripe_checkout_session_id);
                    if ($s->payment_status === 'paid' && $s->payment_intent) {
                        $this->attachIntentToCheckout($payment, $s->payment_intent);
                        $this->applyIntent($stripe->paymentIntents->retrieve($s->payment_intent));
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Stripe sync failed: ' . $e->getMessage());
            }
            if ($b->fresh()->status !== 'pending_payment') {
                break;
            }
        }
    }

    private function attachIntentToCheckout(Payment $payment, string $intentId): void
    {
        if (! $payment->stripe_payment_intent_id) {
            $payment->update(['stripe_payment_intent_id' => $intentId]);
        }
    }

    public function handleEvent(Event $event): void
    {
        $obj = $event->data->object;

        switch ($event->type) {
            case 'checkout.session.completed':
                if (($obj->payment_status ?? null) === 'paid' && $obj->payment_intent) {
                    $payment = Payment::where('stripe_checkout_session_id', $obj->id)->first();
                    if ($payment) {
                        $this->attachIntentToCheckout($payment, $obj->payment_intent);
                        $this->applyIntent($this->client()->paymentIntents->retrieve($obj->payment_intent));
                    }
                }
                break;

            case 'payment_intent.succeeded':
                $this->applyIntent($obj);
                break;

            case 'payment_intent.payment_failed':
                $payment = Payment::where('stripe_payment_intent_id', $obj->id)->first();
                if ($payment && $payment->status === 'requires_payment') {
                    $msg = $obj->last_payment_error->message ?? 'The payment failed.';
                    $payment->update(['failure_message' => mb_substr($msg, 0, 250)]);
                    $payment->booking?->log('payment_failed', 'system', null, null, $msg);
                }
                break;

            case 'charge.refunded':
                $payment = Payment::where('stripe_payment_intent_id', $obj->payment_intent ?? '')->first();
                if ($payment) {
                    $refunded = round(((int) $obj->amount_refunded) / 100, 2);
                    $payment->update([
                        'refunded_total' => $refunded,
                        'status' => $refunded >= (float) $payment->amount ? 'refunded' : 'partially_refunded',
                    ]);
                }
                break;
        }
    }

    /** Idempotent: safe to run from the webhook, the sync call, or both. */
    public function applyIntent(PaymentIntent $pi): ?Payment
    {
        $payment = Payment::where('stripe_payment_intent_id', $pi->id)->first();
        if (! $payment || $pi->status !== 'succeeded') {
            return $payment;
        }

        // Card details for the receipt line.
        $brand = $last4 = $chargeId = null;
        try {
            $full = $this->client()->paymentIntents->retrieve($pi->id, ['expand' => ['latest_charge']]);
            $charge = $full->latest_charge;
            $chargeId = $charge->id ?? null;
            $brand = $charge->payment_method_details->card->brand ?? null;
            $last4 = $charge->payment_method_details->card->last4 ?? null;
        } catch (\Throwable $e) {
            Log::warning('Stripe charge lookup failed: ' . $e->getMessage());
        }

        $refundNeeded = false;

        DB::transaction(function () use ($payment, $brand, $last4, $chargeId, &$refundNeeded) {
            $p = Payment::lockForUpdate()->find($payment->id);
            if ($p->status === 'succeeded' || in_array($p->status, ['refunded', 'partially_refunded'], true)) {
                return;
            }
            $p->update(['status' => 'succeeded', 'paid_at' => now(), 'stripe_charge_id' => $chargeId, 'card_brand' => $brand, 'card_last4' => $last4, 'failure_message' => null]);

            $b = Booking::lockForUpdate()->find($p->booking_id);
            if (! $b) {
                return;
            }
            $label = $b->money($p->amount) . ($brand ? ' by ' . ucfirst($brand) . ' •••• ' . $last4 : '');

            if ($b->status === 'pending_payment') {
                $b->update(['status' => 'confirmed']);
                $b->log('payment_succeeded', 'system', 'pending_payment', 'confirmed', 'Paid ' . $label . '.');
            } elseif ($b->status === 'cancelled') {
                // Paid after the booking was cancelled: give the money back.
                $refundNeeded = true;
                $b->log('payment_late', 'system', null, null, 'Payment of ' . $label . ' arrived after cancellation; refunding.');
            } else {
                $b->log('payment_received', 'system', null, null, 'Paid ' . $label . '.');
            }
        });

        if ($refundNeeded) {
            try {
                $this->client()->refunds->create(['payment_intent' => $pi->id]);
            } catch (\Throwable $e) {
                Log::error('Auto-refund failed for ' . $pi->id . ': ' . $e->getMessage());
            }
        }

        return $payment->fresh();
    }
}