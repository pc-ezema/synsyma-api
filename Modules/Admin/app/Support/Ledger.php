<?php

namespace Modules\Admin\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\User\Models\Booking;
use Modules\User\Models\Payment;
use Modules\Admin\Models\PayoutEntry;
use Modules\Admin\Models\PayoutSetting;

/**
 * What each contractor is owed. An "earning" is created once, when a job is completed:
 *   what the customer actually paid (after discounts, with extra hours, minus refunds) less our commission.
 * If money is refunded afterwards, an "adjustment" entry (negative) is added, so the balance always follows the payments.
 * Entries are only ever added, never edited, which keeps a clean trail.
 */
class Ledger
{
    private const PAID = ['succeeded', 'partially_refunded', 'refunded'];

    /** Customer money kept on this booking: everything paid, less everything refunded. */
    public static function netPaid(Booking $b): float
    {
        $rows = Payment::where('booking_id', $b->id)->whereIn('status', self::PAID)->get(['amount', 'refunded_total']);

        return round($rows->sum(fn ($p) => (float) $p->amount - (float) $p->refunded_total), 2);
    }

    /** The contractor's own percentage if set, otherwise the platform default. */
    public static function commissionFor(Booking $b): float
    {
        $own = $b->contractor?->commission_percent;

        return $own !== null ? (float) $own : (float) PayoutSetting::current()->default_commission_percent;
    }

    /** Called when a booking becomes completed. Safe to call twice. */
    public static function onCompleted(Booking $b, ?string $note = null): void
    {
        if (! $b->contractor_id) {
            return;
        }
        $net = self::netPaid($b);
        if ($net <= 0) {
            return; // a free booking has nothing to share
        }

        $pct = self::commissionFor($b);
        $earning = round($net * (100 - $pct) / 100, 2);
        $hold = (int) PayoutSetting::current()->hold_days;

        try {
            PayoutEntry::create([
                'contractor_id' => $b->contractor_id, 'booking_id' => $b->id, 'type' => 'earning', 'unique_key' => 'earning:' . $b->id,
                'amount' => $earning, 'gross' => $net, 'commission_percent' => $pct, 'currency' => $b->currency ?: 'USD',
                'note' => $note, 'payable_at' => ($b->completed_at ?? now())->copy()->addDays($hold),
            ]);
        } catch (QueryException $e) {
            // Already created by another request: that is fine.
            if (! str_contains(strtolower($e->getMessage()), 'duplicate') && ! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }
        }
    }

    /** Called after a payment's refunded total changes. Adds an adjustment so the balance matches what was kept. */
    public static function sync(Booking $b): void
    {
        DB::transaction(function () use ($b) {
            $earning = PayoutEntry::where('booking_id', $b->id)->where('type', 'earning')->lockForUpdate()->first();
            if (! $earning) {
                return; // not completed yet: the earning will be worked out from the then-current payments
            }

            $net = self::netPaid($b);
            $target = round($net * (100 - (float) $earning->commission_percent) / 100, 2);
            $current = round((float) PayoutEntry::where('booking_id', $b->id)->sum('amount'), 2);
            $diff = round($target - $current, 2);
            if (abs($diff) < 0.01) {
                return;
            }

            PayoutEntry::create([
                'contractor_id' => $earning->contractor_id, 'booking_id' => $b->id, 'type' => 'adjustment',
                'amount' => $diff, 'gross' => $net, 'commission_percent' => $earning->commission_percent, 'currency' => $earning->currency,
                'note' => $diff < 0 ? 'Customer refund: contractor share reduced.' : 'Correction: contractor share increased.',
                // Still waiting to be paid out? Then it waits with the earning. Already paid? It is owed back straight away.
                'payable_at' => $earning->payout_id ? now() : $earning->payable_at,
            ]);
        });
    }

    /** For the booking page. */
    public static function forBooking(Booking $b): ?array
    {
        $entries = PayoutEntry::where('booking_id', $b->id)->orderBy('created_at')->get();
        $earning = $entries->firstWhere('type', 'earning');
        if (! $earning) {
            return null;
        }

        return [
            'contractor_share' => round((float) $entries->sum('amount'), 2),
            'commission_percent' => (float) $earning->commission_percent,
            'customer_paid' => (float) $earning->gross,
            'state' => $earning->state(),
            'payable_at' => $earning->payable_at?->toIso8601String(),
        ];
    }
}