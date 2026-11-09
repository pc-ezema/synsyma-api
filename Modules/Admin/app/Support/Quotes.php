<?php

namespace Modules\Admin\Support; // the same file also goes in Modules/User/app/Support with the namespace changed to Modules\User\Support

use Illuminate\Support\Carbon;

/** The life of a quote: requested -> sent -> accepted (then it is paid like any booking), or expired / closed. */
class Quotes
{
    public const MAX_PRICE = 100000;
    public const DEFAULT_DAYS = 7;

    /** requested | sent | expired | accepted | closed, or null when the booking is not priced by quote. */
    public static function state($b): ?string
    {
        if ($b->price_type !== 'quote') {
            return null;
        }
        if ($b->status === 'quote_requested') {
            return 'requested';
        }
        if ($b->status === 'quote_sent') {
            return $b->quote_expires_at && $b->quote_expires_at->isPast() ? 'expired' : 'sent';
        }
        if ($b->status !== 'cancelled' || $b->quote_accepted_at) {
            return 'accepted'; // pending payment, paid, assigned, done... all follow an accepted quote
        }
        if ($b->quote_expires_at && $b->cancelled_at && $b->cancelled_at->gte($b->quote_expires_at)) {
            return 'expired';
        }

        return 'closed';
    }

    /** Safe for the customer and the admin. Null for ordinary bookings. */
    public static function info($b): ?array
    {
        $state = self::state($b);
        if ($state === null) {
            return null;
        }
        $priced = $b->quote_sent_at !== null && (float) $b->total > 0;

        return [
            'state' => $state,
            'price' => $priced ? (float) $b->total : null,
            'price_label' => $priced ? $b->money($b->total) : null,
            'message' => $b->quote_message,
            'sent_at' => $b->quote_sent_at?->toIso8601String(),
            'expires_at' => $b->quote_expires_at?->toIso8601String(),
            'accepted_at' => $b->quote_accepted_at?->toIso8601String(),
            'can_accept' => $state === 'sent',
            'can_decline' => in_array($state, ['requested', 'sent'], true),
        ];
    }

    /** End of the chosen last day, in the customer's own time zone, so "7 days" never ends mid-evening. */
    public static function expiryFor($b, int $days): Carbon
    {
        return Carbon::now($b->timezone)->addDays($days)->endOfDay()->utc();
    }
}