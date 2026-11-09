<?php

namespace Modules\User\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\User\Models\Booking;
use Modules\User\Models\Payment;
use RuntimeException;

/**
 * Everything that talks to Stripe, through its REST API (no SDK to install).
 * The webhook is the source of truth; the app's "sync" is a backup.
 * A payment is for the booking itself (purpose "booking") or for extra hours added later (purpose "extra_hours").
 */
class StripeGateway
{
    /** One call to the Stripe API. Returns the decoded JSON as an object. */
    public function api(string $method, string $path, array $params = [], array $headers = []): object
    {
        $key = config('stripe.secret');
        if (! $key) {
            throw new RuntimeException('Stripe is not configured. Set STRIPE_SECRET in the API .env.');
        }

        $req = Http::withToken($key)->withHeaders($headers)->timeout(25)->acceptJson();
        $url = 'https://api.stripe.com/v1' . $path;
        $res = strtoupper($method) === 'GET' ? $req->get($url, $params) : $req->asForm()->post($url, $params);

        $json = json_decode($res->body());
        if (! $res->successful()) {
            throw new RuntimeException($json->error->message ?? ('Stripe error ' . $res->status()));
        }

        return $json;
    }

    /** Checks Stripe's signature header and returns the event. Throws if it is not genuine. */
    public function verifyWebhook(string $payload, string $header, string $secret, int $tolerance = 300): object
    {
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $t = $v;
            } elseif ($k === 'v1') {
                $sigs[] = $v;
            }
        }
        if (! $t || ! $sigs) {
            throw new RuntimeException('Missing signature.');
        }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        $ok = false;
        foreach ($sigs as $sig) {
            $ok = $ok || hash_equals($expected, $sig);
        }
        if (! $ok) {
            throw new RuntimeException('Bad signature.');
        }
        if (abs(time() - (int) $t) > $tolerance) {
            throw new RuntimeException('Signature too old.');
        }
        $event = json_decode($payload);
        if (! is_object($event)) {
            throw new RuntimeException('Bad payload.');
        }

        return $event;
    }

    public function cents(Booking $b): int
    {
        return (int) round(((float) $b->total) * 100);
    }

    /** Why this booking cannot be paid right now, or null if it can. */
    public function blocker(Booking $b): ?string
    {
        if ($b->status === 'quote_requested') {
            return 'This quote has not been priced yet.';
        }
        if ($b->status === 'quote_sent') {
            return 'Accept the quote before paying.';
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

    /**
     * What is being charged: the booking total, or an extra-hours charge from BookingChanges::extraCharge().
     * Returns [amount (float), purpose, hours, label].
     */
    private function chargeFor(Booking $b, ?array $charge): array
    {
        if ($charge) {
            return [(float) $charge['amount'], $charge['purpose'], $charge['hours'] ?? null, $charge['label'] ?? 'Extra hours'];
        }

        return [(float) $b->total, 'booking', null, $b->service_name];
    }

    public function customerFor($user, Booking $b): string
    {
        if (! empty($user->stripe_customer_id)) {
            return $user->stripe_customer_id;
        }
        $c = $this->api('POST', '/customers', [
            'email' => $user->email ?? null,
            'name' => $b->customerName(),
            'metadata' => ['user_id' => (string) $user->id],
        ]);
        $user->forceFill(['stripe_customer_id' => $c->id])->save();

        return $c->id;
    }

    /** Mobile payment sheet: a PaymentIntent (reused if the same one is still open). */
    public function sheetFor(Booking $b, $user, ?array $charge = null): array
    {
        [$amount, $purpose, $hours, $label] = $this->chargeFor($b, $charge);
        $customer = $this->customerFor($user, $b);
        $cents = (int) round($amount * 100);

        $payment = null;
        $intent = null;

        $open = Payment::where('booking_id', $b->id)->where('purpose', $purpose)->where('status', 'requires_payment')
            ->whereNotNull('stripe_payment_intent_id')->latest()->first();
        if ($open && (int) round(((float) $open->amount) * 100) === $cents && (int) $open->hours === (int) $hours) {
            $pi = $this->api('GET', '/payment_intents/' . $open->stripe_payment_intent_id);
            if (in_array($pi->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true) && (int) $pi->amount === $cents) {
                $payment = $open;
                $intent = $pi;
            }
        }

        if (! $intent) {
            $intent = $this->api('POST', '/payment_intents', [
                'amount' => $cents,
                'currency' => config('stripe.currency'),
                'customer' => $customer,
                'description' => $label . ' · ' . $b->reference,
                'automatic_payment_methods' => ['enabled' => 'true'],
                // 'setup_future_usage' => 'on_session', // lets the customer save the card
                'receipt_email' => $user->email ?? null,
                'metadata' => ['booking_id' => $b->id, 'reference' => $b->reference, 'user_id' => (string) $user->id, 'purpose' => $purpose],
            ]);

            $payment = Payment::create([
                'booking_id' => $b->id, 'user_id' => $user->id, 'purpose' => $purpose, 'hours' => $hours, 'amount' => $amount, 'currency' => 'USD',
                'status' => 'requires_payment', 'stripe_payment_intent_id' => $intent->id,
            ]);
        }

        $ephemeral = null;
        try {
            $ephemeral = $this->api('POST', '/ephemeral_keys', ['customer' => $customer], ['Stripe-Version' => config('stripe.ephemeral_version')])->secret;
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
            'amount' => $amount,
            'currency' => 'USD',
        ];
    }

    /** Web build: a hosted Stripe Checkout page. */
    public function checkoutFor(Booking $b, $user, string $returnUrl, ?array $charge = null): array
    {
        [$amount, $purpose, $hours, $label] = $this->chargeFor($b, $charge);
        $customer = $this->customerFor($user, $b);
        $sep = str_contains($returnUrl, '?') ? '&' : '?';

        $session = $this->api('POST', '/checkout/sessions', [
            'mode' => 'payment',
            'customer' => $customer,
            'client_reference_id' => $b->id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => config('stripe.currency'),
                    'unit_amount' => (int) round($amount * 100),
                    'product_data' => ['name' => $label, 'description' => 'Booking ' . $b->reference],
                ],
            ]],
            'payment_intent_data' => [
                'description' => $label . ' · ' . $b->reference,
                'metadata' => ['booking_id' => $b->id, 'reference' => $b->reference, 'user_id' => (string) $user->id, 'purpose' => $purpose],
            ],
            'saved_payment_method_options' => [
                'payment_method_save' => 'enabled',                          // shows "Save my card for next time"
                'allow_redisplay_filters' => ['always', 'limited', 'unspecified'], // also shows cards saved before this change
            ],
            'metadata' => ['booking_id' => $b->id],
            'success_url' => $returnUrl . $sep . 'paid=1',
            'cancel_url' => $returnUrl . $sep . 'paid=0',
        ]);

        Payment::create([
            'booking_id' => $b->id, 'user_id' => $user->id, 'purpose' => $purpose, 'hours' => $hours, 'amount' => $amount, 'currency' => 'USD',
            'status' => 'requires_payment', 'stripe_checkout_session_id' => $session->id,
        ]);

        return ['mode' => 'checkout', 'url' => $session->url];
    }

    /** Backup for when the webhook has not arrived (or is not running locally): ask the gateways directly. */
    public function syncBooking(Booking $b): void
    {
        app(PayPalGateway::class)->syncBooking($b);

        $open = Payment::where('booking_id', $b->id)->where('provider', 'stripe')->where('status', 'requires_payment')
            ->where('created_at', '>', now()->subDays(3))->latest()->limit(10)->get();

        foreach ($open as $payment) {
            try {
                if ($payment->stripe_payment_intent_id) {
                    $this->applyIntent($this->api('GET', '/payment_intents/' . $payment->stripe_payment_intent_id));
                } elseif ($payment->stripe_checkout_session_id) {
                    $s = $this->api('GET', '/checkout/sessions/' . $payment->stripe_checkout_session_id);
                    if ($s->payment_status === 'paid' && $s->payment_intent) {
                        $this->attachIntentToCheckout($payment, $s->payment_intent);
                        $this->applyIntent($this->api('GET', '/payment_intents/' . $s->payment_intent));
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Stripe sync failed: ' . $e->getMessage());
            }
        }
    }

    private function attachIntentToCheckout(Payment $payment, string $intentId): void
    {
        if (! $payment->stripe_payment_intent_id) {
            $payment->update(['stripe_payment_intent_id' => $intentId]);
        }
    }

    public function handleEvent(object $event): void
    {
        $obj = $event->data->object;

        switch ($event->type) {
            case 'checkout.session.completed':
                if (($obj->payment_status ?? null) === 'paid' && $obj->payment_intent) {
                    $payment = Payment::where('stripe_checkout_session_id', $obj->id)->first();
                    if ($payment) {
                        $this->attachIntentToCheckout($payment, $obj->payment_intent);
                        $this->applyIntent($this->api('GET', '/payment_intents/' . $obj->payment_intent));
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
    public function applyIntent(object $pi): ?Payment
    {
        $payment = Payment::where('stripe_payment_intent_id', $pi->id)->first();
        if (! $payment || $pi->status !== 'succeeded') {
            return $payment;
        }

        // Card details for the receipt line.
        $brand = $last4 = $chargeId = null;
        try {
            $full = $this->api('GET', '/payment_intents/' . $pi->id, ['expand' => ['latest_charge']]);
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

            if ($p->purpose === 'extra_hours') {
                if (in_array($b->status, ['confirmed', 'assigned', 'in_progress'], true)) {
                    BookingChanges::applyExtraHours($b, $p);
                } else {
                    $refundNeeded = true;
                    $b->log('payment_late', 'system', null, null, 'Payment of ' . $label . ' for extra hours arrived after the booking ended; refunding.');
                }

                return;
            }

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
                $this->api('POST', '/refunds', ['payment_intent' => $pi->id]);
                $payment->fresh()->update(['refunded_total' => $payment->amount, 'status' => 'refunded']);
            } catch (\Throwable $e) {
                Log::error('Auto-refund failed for ' . $pi->id . ': ' . $e->getMessage());
            }
        }

        return $payment->fresh();
    }

    /** Money still refundable on a payment. */
    public function refundable(Payment $p): float
    {
        return max(0.0, round((float) $p->amount - (float) $p->refunded_total, 2));
    }

    /** Refundable across all of a booking's payments (the booking itself and any extra hours). */
    public function refundableTotal(Booking $b): float
    {
        return round($b->payments()->whereIn('status', ['succeeded', 'partially_refunded'])->get()->sum(fn (Payment $p) => $this->refundable($p)), 2);
    }

    /** Refunds up to $amount, newest payment first. Returns what was actually refunded. Throws if a gateway refuses. */
    public function refundBooking(Booking $b, float $amount): float
    {
        $left = round($amount, 2);
        $done = 0.0;
        $payments = $b->payments()->whereIn('status', ['succeeded', 'partially_refunded'])->get()->sortByDesc('created_at');

        foreach ($payments as $p) {
            if ($left <= 0) {
                break;
            }
            $d = $this->refund($p, $left);
            $done = round($done + $d, 2);
            $left = round($left - $d, 2);
        }

        return $done;
    }

    /** Refunds part or all of a payment through its gateway and records it. Throws if the gateway refuses. */
    public function refund(Payment $p, float $amount, string $reason = 'requested_by_customer'): float
    {
        if ($p->provider === 'paypal') {
            return app(PayPalGateway::class)->refund($p, $amount);
        }

        $amount = min(round($amount, 2), $this->refundable($p));
        if ($amount <= 0 || ! $p->stripe_payment_intent_id) {
            return 0.0;
        }
        $cents = (int) round($amount * 100);

        // The key includes what was already refunded, so a retry of the same request cannot refund twice.
        $this->api('POST', '/refunds', [
            'payment_intent' => $p->stripe_payment_intent_id,
            'amount' => $cents,
            'reason' => $reason,
            'metadata' => ['booking_id' => $p->booking_id, 'payment_id' => $p->id],
        ], [
            'Idempotency-Key' => 'refund-' . $p->id . '-' . (int) round(((float) $p->refunded_total) * 100) . '-' . $cents,
        ]);

        $total = round((float) $p->refunded_total + $amount, 2);
        $p->update(['refunded_total' => $total, 'status' => $total >= (float) $p->amount ? 'refunded' : 'partially_refunded']);

        return $amount;
    }
}