<?php

namespace Modules\User\Support;

use Modules\User\Models\Booking;
use Modules\Admin\Models\BookingPolicy;
use Modules\User\Models\Payment;

/** Works out what cancelling a booking costs the customer. */
class CancellationPolicy
{
    public const CANCELLABLE = ['pending_payment', 'confirmed', 'assigned'];

    /** What the customer actually paid for this booking (and has not had back yet). */
    public static function paidPayment(Booking $b): ?Payment
    {
        return $b->payments()->where('purpose', 'booking')->whereIn('status', ['succeeded', 'partially_refunded'])->latest()->first();
    }

    /**
     * allowed:  may the customer cancel online at all
     * tier:     free | partial | none (none = no refund)
     * The money figures are always filled in, so the admin can reuse them when overriding.
     */
    public static function quote(Booking $b): array
    {
        $pol = BookingPolicy::current();
        $paid = (float) $b->payments()->whereIn('status', ['succeeded', 'partially_refunded'])->get()
            ->sum(fn ($p) => max(0.0, (float) $p->amount - (float) $p->refunded_total));

        $hours = ($b->starts_at->getTimestamp() - now()->getTimestamp()) / 3600; // negative once started
        $assigned = $b->status === 'assigned' && $b->contractor_id;

        if (! $assigned || $hours > $pol->free_cancel_hours) {
            $tier = 'free';
            $pct = 0;
        } elseif ($hours >= $pol->late_cancel_hours) {
            $tier = 'partial';
            $pct = $pol->late_cancel_fee_percent;
        } else {
            $tier = 'none';
            $pct = 100;
        }

        $fee = round($paid * $pct / 100, 2);
        $refund = round($paid - $fee, 2);

        $allowed = in_array($b->status, self::CANCELLABLE, true) && $hours > 0;
        $currency = $b->currency;
        $m = fn (float $v) => $b->money($v);

        $message = match (true) {
            ! in_array($b->status, self::CANCELLABLE, true) => 'This booking can no longer be cancelled online. Please contact support.',
            $hours <= 0 => 'The start time has passed, so this booking cannot be cancelled online. Please contact support.',
            $paid <= 0 => 'This booking has not been paid, so there is nothing to refund.',
            $tier === 'free' => "Free cancellation. You will be refunded {$m($refund)}.",
            $tier === 'partial' => "A {$pct}% late-cancellation fee ({$m($fee)}) applies. You will be refunded {$m($refund)}.",
            default => 'This is too close to the start time for a refund.',
        };

        return [
            'allowed' => $allowed,
            'tier' => $tier,
            'fee_percent' => $pct,
            'currency' => $currency,
            'paid' => round($paid, 2),
            'fee' => $fee,
            'refund' => $refund,
            'message' => $message,
        ];
    }
}