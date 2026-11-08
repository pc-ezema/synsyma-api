<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\Contractor;
use Modules\Admin\Models\Payout;
use Modules\Admin\Models\PayoutEntry;
use Modules\Admin\Models\PayoutSetting;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin: what contractors are owed and what has been paid.
 * Guarded with can:payouts.view (read) and can:payouts.manage (pay, settings).
 * Money is sent by you outside the app; "pay" records it and closes the entries it covers.
 */
class AdminPayoutController extends Controller
{
    /** One row per contractor with any ledger activity. */
    public function index(Request $request): JsonResponse
    {
        $now = now()->format('Y-m-d H:i:s');

        $q = PayoutEntry::query()->selectRaw(
            'contractor_id,
             COALESCE(SUM(CASE WHEN payout_id IS NULL AND payable_at <= ? THEN amount END),0) as payable,
             COALESCE(SUM(CASE WHEN payout_id IS NULL AND payable_at > ? THEN amount END),0) as on_hold,
             COALESCE(SUM(CASE WHEN payout_id IS NOT NULL THEN amount END),0) as paid,
             COUNT(*) as entries',
            [$now, $now]
        )->groupBy('contractor_id');

        if ($s = trim((string) $request->query('search'))) {
            $ids = Contractor::where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))->pluck('id');
            $q->whereIn('contractor_id', $ids);
        }
        if ($request->query('show') === 'owed') {
            $q->havingRaw('payable > 0');
        } elseif ($request->query('show') === 'recover') {
            $q->havingRaw('payable < 0');
        }

        $page = $q->orderByRaw('payable desc')->paginate(min(max((int) $request->query('per_page', 15), 1), 100));
        $ids = $page->getCollection()->pluck('contractor_id');
        $people = Contractor::whereIn('id', $ids)->get(['id', 'name', 'email', 'phone'])->keyBy('id');
        $last = Payout::whereIn('contractor_id', $ids)->selectRaw('contractor_id, MAX(paid_at) as last_paid')->groupBy('contractor_id')->pluck('last_paid', 'contractor_id');

        // Totals across everyone, not just this page.
        $t = PayoutEntry::query()->selectRaw(
            'COALESCE(SUM(CASE WHEN payout_id IS NULL AND payable_at <= ? THEN amount END),0) as payable,
             COALESCE(SUM(CASE WHEN payout_id IS NULL AND payable_at > ? THEN amount END),0) as on_hold,
             COALESCE(SUM(CASE WHEN payout_id IS NOT NULL THEN amount END),0) as paid',
            [$now, $now]
        )->first();

        return response()->json([
            'data' => $page->getCollection()->map(function ($r) use ($people, $last) {
                $c = $people->get($r->contractor_id);

                return [
                    'contractor_id' => $r->contractor_id,
                    'name' => $c?->name ?? 'Removed contractor',
                    'email' => $c?->email,
                    'phone' => $c?->phone,
                    'payable' => round((float) $r->payable, 2),
                    'on_hold' => round((float) $r->on_hold, 2),
                    'paid' => round((float) $r->paid, 2),
                    'entries' => (int) $r->entries,
                    'last_paid_at' => isset($last[$r->contractor_id]) ? \Illuminate\Support\Carbon::parse($last[$r->contractor_id])->toIso8601String() : null,
                ];
            })->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'totals' => ['payable' => round((float) $t->payable, 2), 'on_hold' => round((float) $t->on_hold, 2), 'paid' => round((float) $t->paid, 2)],
            'settings' => PayoutSetting::current()->toArrayFlat(),
        ]);
    }

    /** One contractor: balances, every ledger entry, and past payouts. */
    public function show(string $contractorId): JsonResponse
    {
        $c = Contractor::findOrFail($contractorId);
        $now = now();

        $entries = PayoutEntry::with('booking:id,reference,service_name')->where('contractor_id', $c->id)->orderByDesc('created_at')->limit(300)->get();
        $unpaid = PayoutEntry::where('contractor_id', $c->id)->whereNull('payout_id');

        return response()->json(['data' => [
            'contractor' => ['id' => $c->id, 'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'commission_percent' => $c->commission_percent !== null ? (float) $c->commission_percent : null],
            'payable' => round((float) (clone $unpaid)->where('payable_at', '<=', $now)->sum('amount'), 2),
            'on_hold' => round((float) (clone $unpaid)->where('payable_at', '>', $now)->sum('amount'), 2),
            'paid' => round((float) Payout::where('contractor_id', $c->id)->sum('amount'), 2),
            'entries' => $entries->map->toArrayFlat()->values(),
            'payouts' => Payout::withCount('entries')->where('contractor_id', $c->id)->orderByDesc('paid_at')->limit(100)->get()->map->toArrayFlat()->values(),
            'default_commission_percent' => (float) PayoutSetting::current()->default_commission_percent,
        ]]);
    }

    /**
     * Record a payout for everything that is payable now. The screen sends the amount it showed, so a balance that
     * changed in the meantime is refused instead of paid wrongly.
     */
    public function pay(Request $request, string $contractorId): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric'],
            'reference' => ['required', 'string', 'min:2', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['reference.required' => 'Enter the bank or transfer reference for this payment.']);

        $c = Contractor::findOrFail($contractorId);
        $admin = $request->user();

        $payout = DB::transaction(function () use ($c, $data, $admin) {
            $entries = PayoutEntry::where('contractor_id', $c->id)->whereNull('payout_id')->where('payable_at', '<=', now())->lockForUpdate()->get();
            $total = round((float) $entries->sum('amount'), 2);

            if ($total <= 0) {
                throw ValidationException::withMessages(['amount' => $total < 0 ? 'This contractor owes money back, so there is nothing to pay.' : 'There is nothing payable yet.']);
            }
            if (abs($total - round((float) $data['amount'], 2)) > 0.004) {
                throw ValidationException::withMessages(['amount' => 'The balance changed to $' . number_format($total, 2) . '. Reload the page and try again.']);
            }

            $payout = Payout::create([
                'contractor_id' => $c->id, 'amount' => $total, 'currency' => $entries->first()->currency ?: 'USD',
                'reference' => trim($data['reference']), 'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'paid_by' => $admin?->id, 'paid_by_name' => $admin?->name, 'paid_at' => now(),
            ]);
            PayoutEntry::whereIn('id', $entries->pluck('id'))->update(['payout_id' => $payout->id]);

            return $payout;
        });

        return response()->json(['message' => 'Payment of $' . number_format((float) $payout->amount, 2) . ' recorded.', 'data' => $payout->toArrayFlat()]);
    }

    /** CSV of what is ready to pay right now. */
    public function export(): StreamedResponse
    {
        $now = now()->format('Y-m-d H:i:s');
        $rows = PayoutEntry::query()->selectRaw('contractor_id, SUM(amount) as payable, COUNT(*) as entries')
            ->whereNull('payout_id')->where('payable_at', '<=', $now)->groupBy('contractor_id')->havingRaw('SUM(amount) > 0')->orderByRaw('SUM(amount) desc')->get();
        $people = Contractor::whereIn('id', $rows->pluck('contractor_id'))->get(['id', 'name', 'email', 'phone'])->keyBy('id');
        $name = 'synsyma-payouts-ready-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($rows, $people) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Contractor', 'Email', 'Phone', 'Currency', 'Amount to pay', 'Jobs']);
            foreach ($rows as $r) {
                $c = $people->get($r->contractor_id);
                fputcsv($out, array_map([$this, 'safe'], [$c?->name ?? '', $c?->email ?? '', $c?->phone ?? '', 'USD', number_format((float) $r->payable, 2, '.', ''), $r->entries]));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => PayoutSetting::current()->toArrayFlat()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'default_commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'hold_days' => ['required', 'integer', 'min:0', 'max:60'],
        ]);
        $s = PayoutSetting::current();
        $s->update($data);

        return response()->json(['message' => 'Payout settings saved. They apply to jobs completed from now on.', 'data' => $s->toArrayFlat()]);
    }

    /** Keeps spreadsheet programs from running a cell as a formula. */
    private function safe(mixed $v): string
    {
        $v = (string) $v;

        return $v !== '' && str_contains('=+-@', $v[0]) ? "'" . $v : $v;
    }
}