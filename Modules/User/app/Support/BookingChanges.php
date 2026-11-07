<?php

namespace Modules\User\Support;

use Illuminate\Validation\ValidationException;
use Modules\User\Models\Booking;
use Modules\Admin\Models\BookingPolicy;
use Modules\User\Models\Payment;

/** What a customer may change on their booking, from the admin-editable policy. */
class BookingChanges
{
    public static function rescheduleRules(Booking $b): array
    {
        $pol = BookingPolicy::current();
        $left = max(0, $pol->free_reschedules - (int) $b->reschedule_count);
        $hours = ($b->starts_at->getTimestamp() - now()->getTimestamp()) / 3600;

        if (! in_array($b->status, ['pending_payment', 'confirmed', 'assigned'], true)) {
            $allowed = false;
            $msg = 'This booking can no longer be rescheduled.';
        } elseif ($left <= 0) {
            $allowed = false;
            $msg = $pol->free_reschedules > 0
                ? 'You have used your free reschedule. Please contact support to change the time again.'
                : 'Please contact support to change the time of this booking.';
        } elseif ($hours <= $pol->free_cancel_hours) {
            $allowed = false;
            $msg = "You can reschedule up to {$pol->free_cancel_hours} hours before the start. Please contact support.";
        } else {
            $allowed = true;
            $msg = $left === 1 ? 'You can reschedule once for free.' : "You can reschedule {$left} more times for free.";
        }

        return ['allowed' => $allowed, 'left' => $left, 'message' => $msg];
    }

    public static function extraHoursRules(Booking $b): array
    {
        $pol = BookingPolicy::current();
        $more = max(0, $pol->max_extra_hours - (int) $b->extra_hours);
        $price = (float) $b->additional_hour_price;

        if ($b->price_type !== 'hourly' || $price <= 0) {
            $allowed = false;
            $msg = 'Extra hours are only available for hourly services.';
        } elseif (! in_array($b->status, ['assigned', 'in_progress'], true) || now()->lt($b->starts_at)) {
            $allowed = false;
            $msg = 'You can add hours once the contractor has arrived and the job has started.';
        } elseif ($more <= 0) {
            $allowed = false;
            $msg = "You have added the most extra hours allowed ({$pol->max_extra_hours}). Please contact support.";
        } else {
            $allowed = true;
            $msg = 'Need more time? Add up to ' . $more . ' more ' . ($more === 1 ? 'hour' : 'hours') . '.';
        }

        return ['allowed' => $allowed, 'max_more' => $more, 'price_per_hour' => $price, 'currency' => $b->currency, 'message' => $msg];
    }

    /** The money and labels for an extra-hours payment. Throws if the booking cannot have these hours. */
    public static function extraCharge(Booking $b, int $hours): array
    {
        $rules = self::extraHoursRules($b);
        if (! $rules['allowed']) {
            throw ValidationException::withMessages(['booking' => [$rules['message']]]);
        }
        if ($hours < 1 || $hours > $rules['max_more']) {
            throw ValidationException::withMessages(['extra_hours' => ["You can add 1 to {$rules['max_more']} hours."]]);
        }
        if (strtoupper((string) $b->currency) !== 'USD') {
            throw ValidationException::withMessages(['booking' => ['Only USD payments are supported.']]);
        }

        return [
            'purpose' => 'extra_hours',
            'hours' => $hours,
            'amount' => round($rules['price_per_hour'] * $hours, 2),
            'label' => $hours . ' extra ' . ($hours === 1 ? 'hour' : 'hours'),
        ];
    }

    /** Called inside the payment transaction, once, when an extra-hours payment succeeds. */
    public static function applyExtraHours(Booking $b, Payment $p): void
    {
        $b->extra_hours = (int) $b->extra_hours + (int) $p->hours;
        $b->subtotal = round((float) $b->subtotal + (float) $p->amount, 2);
        $b->total = round((float) $b->total + (float) $p->amount, 2);
        $b->save();
        $b->log('extra_hours', 'system', null, null, 'Added ' . $p->hours . ' extra ' . ((int) $p->hours === 1 ? 'hour' : 'hours') . ' (+' . $b->money($p->amount) . ').');
    }
}