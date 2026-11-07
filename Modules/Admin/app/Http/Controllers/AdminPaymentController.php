<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Modules\User\Models\Payment;

/** Admin: every payment, with totals and a CSV export. */
class AdminPaymentController extends Controller
{
    private const PAID = ['succeeded', 'partially_refunded', 'refunded'];

    public function index(Request $request): JsonResponse
    {
        $base = $this->filtered($request);

        $t = (clone $base)->selectRaw(
            'COALESCE(SUM(CASE WHEN status IN (\'succeeded\',\'partially_refunded\',\'refunded\') THEN amount END),0) as collected,
             COALESCE(SUM(refunded_total),0) as refunded,
             SUM(CASE WHEN status IN (\'succeeded\',\'partially_refunded\',\'refunded\') THEN 1 ELSE 0 END) as paid_count,
             COUNT(*) as all_count'
        )->reorder()->first();

        $page = (clone $base)->with(['booking.user'])->orderByRaw('COALESCE(paid_at, created_at) desc')
            ->paginate(min((int) $request->query('per_page', 15), 100));

        $collected = round((float) $t->collected, 2);
        $refunded = round((float) $t->refunded, 2);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Payment $p) => $this->row($p))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'gateways' => [
                ['id' => 'card', 'label' => 'Card (Stripe)', 'on' => (bool) config('stripe.secret'), 'mode' => str_contains((string) config('stripe.secret'), '_live_') ? 'live' : 'test'],
                ['id' => 'paypal', 'label' => 'PayPal', 'on' => (bool) (config('paypal.client_id') && config('paypal.secret')), 'mode' => config('paypal.mode') === 'live' ? 'live' : 'test'],
            ],
            'totals' => [
                'collected' => $collected,
                'refunded' => $refunded,
                'net' => round($collected - $refunded, 2),
                'paid_count' => (int) $t->paid_count,
                'count' => (int) $t->all_count,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $q = $this->filtered($request)->with(['booking.user'])->orderByRaw('COALESCE(paid_at, created_at) desc');
        $name = 'synsyma-payments-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date (UTC)', 'Booking', 'Customer', 'Email', 'Service', 'Type', 'Status', 'Currency', 'Amount', 'Refunded', 'Net', 'Method', 'Gateway reference']);
            $q->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $p) {
                    $r = $this->row($p);
                    fputcsv($out, array_map([$this, 'safe'], [
                        $r['date'], $r['reference'], $r['customer_name'], $r['customer_email'], $r['service_name'], $r['purpose'] === 'extra_hours' ? 'Extra hours' : 'Booking', $r['state'],
                        $r['currency'], number_format($r['amount'], 2, '.', ''), number_format($r['refunded_total'], 2, '.', ''), number_format($r['net'], 2, '.', ''),
                        $r['card'], $r['stripe_payment_intent_id'],
                    ]));
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Keeps spreadsheet programs from running a cell as a formula. */
    private function safe(mixed $v): string
    {
        $v = (string) $v;

        return $v !== '' && str_contains('=+-@', $v[0]) ? "'" . $v : $v;
    }

    private function row(Payment $p): array
    {
        $b = $p->booking;
        $paid = in_array($p->status, self::PAID, true);

        return [
            'id' => $p->id,
            'booking_id' => $p->booking_id,
            'reference' => $b?->reference,
            'customer_name' => $b?->customerName() ?? '—',
            'customer_email' => $b?->user?->email,
            'service_name' => $b?->service_name,
            'state' => $p->state(),
            'currency' => $p->currency,
            'amount' => (float) $p->amount,
            'refunded_total' => (float) $p->refunded_total,
            'net' => $paid ? round((float) $p->amount - (float) $p->refunded_total, 2) : 0.0,
            'provider' => $p->provider,
            'purpose' => $p->purpose,
            'hours' => $p->hours,
            'card' => $p->provider === 'paypal' ? 'PayPal' : ($p->card_last4 ? ucfirst((string) ($p->card_brand ?: 'card')) . ' •••• ' . $p->card_last4 : ''),
            'stripe_payment_intent_id' => $p->stripe_payment_intent_id ?? $p->paypal_order_id,
            'failure_message' => $p->failure_message,
            'date' => ($p->paid_at ?? $p->created_at)?->utc()->format('Y-m-d H:i'),
            'paid_at' => $p->paid_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    private function filtered(Request $request): Builder
    {
        $q = Payment::query();

        match ($request->query('status')) {
            'paid' => $q->whereIn('status', self::PAID),
            'refunded' => $q->whereIn('status', ['refunded', 'partially_refunded']),
            'failed' => $q->where('status', 'requires_payment')->whereNotNull('failure_message'),
            'unpaid' => $q->where('status', 'requires_payment')->whereNull('failure_message'),
            default => null,
        };

        if ($from = $this->date($request->query('from'))) {
            $q->whereRaw('COALESCE(payments.paid_at, payments.created_at) >= ?', [$from . ' 00:00:00']);
        }
        if ($to = $this->date($request->query('to'))) {
            $q->whereRaw('COALESCE(payments.paid_at, payments.created_at) <= ?', [$to . ' 23:59:59']);
        }

        if ($s = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('stripe_payment_intent_id', 'like', "%{$s}%")
                ->orWhereHas('booking', fn ($b) => $b->where('reference', 'like', "%{$s}%")
                    ->orWhere('service_name', 'like', "%{$s}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$s}%"))));
        }

        return $q;
    }

    private function date(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}