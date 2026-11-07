<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\User\Models\Payment;
use Modules\User\Support\PayPalGateway;

/** The public endpoints PayPal and the customer's browser hit. */
class PayPalController extends Controller
{
    public function __construct(private readonly PayPalGateway $paypal)
    {
    }

    /** PayPal sends the customer here after they approve. We capture, then send them back to the app. */
    public function approved(Request $request): RedirectResponse|Response
    {
        $p = Payment::where('provider', 'paypal')->where('paypal_order_id', (string) $request->query('token'))->first();
        if (! $p) {
            return $this->page('We could not find this payment.', false);
        }

        $paid = false;
        try {
            $paid = $this->paypal->settle($p);
        } catch (\Throwable $e) {
            Log::error('PayPal return capture failed: ' . $e->getMessage());
        }

        if ($p->return_url) {
            return redirect()->away($p->return_url . (str_contains($p->return_url, '?') ? '&' : '?') . 'paid=' . ($paid ? '1' : '0'));
        }

        return $paid
            ? $this->page('Payment received. You can close this window and go back to the Synsyma app.', true)
            : $this->page('We are still confirming your payment. Go back to the Synsyma app; your booking will update in a moment.', true);
    }

    public function cancelled(Request $request): RedirectResponse|Response
    {
        $p = Payment::where('provider', 'paypal')->find((string) $request->query('p'));

        if ($p?->return_url) {
            return redirect()->away($p->return_url . (str_contains($p->return_url, '?') ? '&' : '?') . 'paid=0');
        }

        return $this->page('Payment cancelled. You can close this window and go back to the Synsyma app.', false);
    }

    public function webhook(Request $request): JsonResponse
    {
        $headers = array_change_key_case(array_map(fn ($v) => is_array($v) ? ($v[0] ?? '') : $v, $request->headers->all()), CASE_LOWER);

        try {
            $event = $this->paypal->verifyWebhook($headers, $request->getContent());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        try {
            $this->paypal->handleEvent($event);
        } catch (\Throwable $e) {
            Log::error('PayPal webhook ' . ($event->event_type ?? '?') . ' failed: ' . $e->getMessage());

            return response()->json(['message' => 'Handler failed.'], 500); // PayPal retries
        }

        return response()->json(['message' => 'Received.']);
    }

    private function page(string $message, bool $ok): Response
    {
        $color = $ok ? '#0066FF' : '#6B7280';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synsyma</title></head>'
            . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#F0F2F7;font-family:system-ui,sans-serif">'
            . '<div style="max-width:360px;margin:24px;padding:28px;background:#fff;border-radius:20px;text-align:center">'
            . '<div style="font-size:20px;font-weight:700;color:' . $color . ';margin-bottom:10px">Synsyma</div>'
            . '<p style="margin:0;line-height:1.5;color:#1F2430">' . e($message) . '</p></div></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}