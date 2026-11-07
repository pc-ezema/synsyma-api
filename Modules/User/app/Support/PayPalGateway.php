<?php

namespace Modules\User\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\User\Models\Booking;
use Modules\User\Models\Payment;
use RuntimeException;

/**
 * PayPal through its REST API (Orders v2), no SDK needed.
 * Flow: create an order -> the customer approves it on PayPal -> we capture it -> the booking is confirmed.
 * The return page and the app's sync both capture; the webhook is the backup.
 */
class PayPalGateway
{
    public function enabled(): bool
    {
        return (bool) (config('paypal.client_id') && config('paypal.secret'));
    }

    private function base(): string
    {
        return config('paypal.mode') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function token(bool $fresh = false): string
    {
        $key = 'paypal_access_token_' . config('paypal.mode');
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 3000, function () {
            $res = Http::asForm()->withBasicAuth((string) config('paypal.client_id'), (string) config('paypal.secret'))
                ->timeout(25)->post($this->base() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            if (! $res->successful()) {
                throw new RuntimeException('PayPal sign-in failed. Check PAYPAL_CLIENT_ID, PAYPAL_SECRET and PAYPAL_MODE.');
            }

            return (string) $res->json('access_token');
        });
    }

    /** One call to the PayPal API. $json is sent as the JSON body (use [] for an empty one). */
    public function api(string $method, string $path, ?array $json = null, array $headers = [], bool $retried = false): object
    {
        if (! $this->enabled()) {
            throw new RuntimeException('PayPal is not configured. Set PAYPAL_CLIENT_ID and PAYPAL_SECRET in the API .env.');
        }

        $req = Http::withToken($this->token($retried))->withHeaders($headers)->timeout(25)->acceptJson();
        $url = $this->base() . $path;
        $res = strtoupper($method) === 'GET'
            ? $req->get($url)
            : $req->withBody($json === null ? '{}' : json_encode($json, JSON_UNESCAPED_SLASHES), 'application/json')->post($url);

        if ($res->status() === 401 && ! $retried) {
            return $this->api($method, $path, $json, $headers, true); // the cached token expired
        }

        $body = json_decode($res->body());
        if (! $res->successful()) {
            throw new RuntimeException($body->details[0]->description ?? $body->message ?? ('PayPal error ' . $res->status()));
        }

        return is_object($body) ? $body : new \stdClass();
    }

    /** Starts a payment: returns the PayPal page the customer must approve on. */
    public function checkoutFor(Booking $b, $user, ?string $appReturnUrl, ?array $charge = null): array
    {
        $amount = $charge ? (float) $charge['amount'] : (float) $b->total;
        $label = $charge['label'] ?? $b->service_name;

        $payment = Payment::create([
            'booking_id' => $b->id, 'user_id' => $user->id, 'provider' => 'paypal', 'purpose' => $charge['purpose'] ?? 'booking',
            'hours' => $charge['hours'] ?? null, 'amount' => $amount, 'currency' => 'USD',
            'status' => 'requires_payment', 'return_url' => $appReturnUrl,
        ]);

        $order = $this->api('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $b->id,
                'custom_id' => $b->id,
                'description' => mb_substr($label . ' · ' . $b->reference, 0, 120),
                'amount' => ['currency_code' => 'USD', 'value' => number_format($amount, 2, '.', '')],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => config('paypal.brand_name'),
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => route('paypal.return'),
                'cancel_url' => route('paypal.cancel', ['p' => $payment->id]),
            ]]],
        ], ['PayPal-Request-Id' => 'order-' . $payment->id]);

        $payment->update(['paypal_order_id' => $order->id]);

        $approve = null;
        foreach ($order->links ?? [] as $l) {
            if (in_array($l->rel, ['payer-action', 'approve'], true)) {
                $approve = $l->href;
                break;
            }
        }
        if (! $approve) {
            throw new RuntimeException('PayPal did not return a payment page.');
        }

        return ['mode' => 'redirect', 'payment_id' => $payment->id, 'url' => $approve];
    }

    /** Captures an approved order and confirms the booking. True once the money is ours. */
    public function settle(Payment $p): bool
    {
        if (! $p->paypal_order_id) {
            return false;
        }
        if ($p->status === 'succeeded' || in_array($p->status, ['refunded', 'partially_refunded'], true)) {
            return true;
        }

        $order = $this->api('GET', '/v2/checkout/orders/' . $p->paypal_order_id);

        if ($order->status === 'APPROVED') {
            try {
                $order = $this->api('POST', '/v2/checkout/orders/' . $order->id . '/capture', null, ['PayPal-Request-Id' => 'capture-' . $p->id]);
            } catch (\Throwable $e) {
                Log::warning('PayPal capture failed, re-reading the order: ' . $e->getMessage());
                $order = $this->api('GET', '/v2/checkout/orders/' . $p->paypal_order_id);
            }
        }

        if (($order->status ?? '') !== 'COMPLETED') {
            return false;
        }
        $cap = $order->purchase_units[0]->payments->captures[0] ?? null;
        if (! $cap || ($cap->status ?? '') !== 'COMPLETED') {
            return false; // pending review: the webhook will finish it
        }
        if (abs((float) $cap->amount->value - (float) $p->amount) > 0.001) {
            Log::error('PayPal amount mismatch on order ' . $p->paypal_order_id);

            return false;
        }

        $this->markPaid($p, (string) $cap->id);

        return true;
    }

    /** Backup for when the customer came back without the return page running. */
    public function syncBooking(Booking $b): void
    {
        $open = Payment::where('booking_id', $b->id)->where('provider', 'paypal')->where('status', 'requires_payment')
            ->whereNotNull('paypal_order_id')->where('created_at', '>', now()->subDays(3))->latest()->limit(10)->get();
        foreach ($open as $p) {
            try {
                $this->settle($p);
            } catch (\Throwable $e) {
                Log::warning('PayPal sync failed: ' . $e->getMessage());
            }
        }
    }

    /** Idempotent. Mirrors what the Stripe side does when a payment succeeds. */
    private function markPaid(Payment $payment, string $captureId): void
    {
        $refundNeeded = false;

        DB::transaction(function () use ($payment, $captureId, &$refundNeeded) {
            $p = Payment::lockForUpdate()->find($payment->id);
            if ($p->status === 'succeeded' || in_array($p->status, ['refunded', 'partially_refunded'], true)) {
                return;
            }
            $p->update(['status' => 'succeeded', 'paid_at' => now(), 'paypal_capture_id' => $captureId, 'card_brand' => 'paypal', 'card_last4' => null, 'failure_message' => null]);

            $b = Booking::lockForUpdate()->find($p->booking_id);
            if (! $b) {
                return;
            }
            $label = $b->money($p->amount) . ' by PayPal';

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
                $refundNeeded = true;
                $b->log('payment_late', 'system', null, null, 'Payment of ' . $label . ' arrived after cancellation; refunding.');
            } else {
                $b->log('payment_received', 'system', null, null, 'Paid ' . $label . '.');
            }
        });

        if ($refundNeeded) {
            try {
                $this->api('POST', '/v2/payments/captures/' . $captureId . '/refund', [], ['PayPal-Request-Id' => 'auto-refund-' . $payment->id]);
                $payment->update(['refunded_total' => $payment->amount, 'status' => 'refunded']);
            } catch (\Throwable $e) {
                Log::error('PayPal auto-refund failed for ' . $captureId . ': ' . $e->getMessage());
            }
        }
    }

    /** Refunds part or all of a captured payment. Throws if PayPal refuses. */
    public function refund(Payment $p, float $amount): float
    {
        $left = max(0.0, round((float) $p->amount - (float) $p->refunded_total, 2));
        $amount = min(round($amount, 2), $left);
        if ($amount <= 0 || ! $p->paypal_capture_id) {
            return 0.0;
        }
        $cents = (int) round($amount * 100);

        $this->api('POST', '/v2/payments/captures/' . $p->paypal_capture_id . '/refund', [
            'amount' => ['value' => number_format($amount, 2, '.', ''), 'currency_code' => 'USD'],
            'note_to_payer' => 'Refund for your Synsyma booking',
        ], [
            // Includes what was already refunded, so a retry of the same request cannot refund twice.
            'PayPal-Request-Id' => 'refund-' . $p->id . '-' . (int) round(((float) $p->refunded_total) * 100) . '-' . $cents,
        ]);

        $total = round((float) $p->refunded_total + $amount, 2);
        $p->update(['refunded_total' => $total, 'status' => $total >= (float) $p->amount ? 'refunded' : 'partially_refunded']);

        return $amount;
    }

    /** Asks PayPal whether a webhook really came from PayPal. */
    public function verifyWebhook(array $headers, string $payload): object
    {
        $event = json_decode($payload);
        $webhookId = config('paypal.webhook_id');
        if (! is_object($event) || ! $webhookId) {
            throw new RuntimeException('Webhook not configured.');
        }

        $res = $this->api('POST', '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $headers['paypal-auth-algo'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]);
        if (($res->verification_status ?? '') !== 'SUCCESS') {
            throw new RuntimeException('Bad signature.');
        }

        return $event;
    }

    public function handleEvent(object $event): void
    {
        $r = $event->resource ?? null;
        if (! $r) {
            return;
        }

        $orderId = match ($event->event_type) {
            'CHECKOUT.ORDER.APPROVED' => $r->id ?? null,
            'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED' => $r->supplementary_data->related_ids->order_id ?? null,
            default => null,
        };
        if (! $orderId) {
            return;
        }
        $payment = Payment::where('paypal_order_id', $orderId)->first();
        if (! $payment) {
            return;
        }

        if ($event->event_type === 'PAYMENT.CAPTURE.DENIED') {
            if ($payment->status === 'requires_payment') {
                $payment->update(['failure_message' => 'PayPal declined the payment.']);
                $payment->booking?->log('payment_failed', 'system', null, null, 'PayPal declined the payment.');
            }

            return;
        }

        $this->settle($payment);
    }
}