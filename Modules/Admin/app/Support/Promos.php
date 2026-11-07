<?php

namespace Modules\Admin\Support;

use Illuminate\Validation\ValidationException;
use Modules\User\Models\Booking;
use Modules\Admin\Models\PromoCode;

/** Checks a promo code and works out the discount. Errors are shown to the customer as they are. */
class Promos
{
    /** Stripe cannot charge less than this, so a code never leaves a total between $0.00 and this. */
    public const MIN_CHARGE = 0.50;

    public static function normalize(?string $code): ?string
    {
        $code = strtoupper(preg_replace('/\s+/', '', (string) $code));

        return $code === '' ? null : $code;
    }

    /** @return array{promo: PromoCode, discount: float} */
    public static function check(string $code, string $userId, float $subtotal): array
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['promo_code' => $msg]);

        $promo = PromoCode::where('code', self::normalize($code))->first();
        if (! $promo || ! $promo->is_active) {
            $fail('That code is not valid.');
        }

        $today = now()->utc()->toDateString();
        if ($promo->starts_on && $promo->starts_on->format('Y-m-d') > $today) {
            $fail('This code is not active yet.');
        }
        if ($promo->expires_on && $promo->expires_on->format('Y-m-d') < $today) {
            $fail('This code has expired.');
        }
        if ($promo->max_uses !== null && $promo->usedCount() >= $promo->max_uses) {
            $fail('This code has reached its limit.');
        }
        if ($promo->usedByUser($userId) >= max(1, (int) $promo->max_uses_per_user)) {
            $fail($promo->max_uses_per_user > 1 ? 'You have used this code the most times allowed.' : 'You have already used this code.');
        }
        if ($promo->first_booking_only && Booking::where('user_id', $userId)->whereNotIn('status', ['pending_payment', 'cancelled'])->exists()) {
            $fail('This code is for first bookings only.');
        }
        if ($promo->min_subtotal !== null && $subtotal < (float) $promo->min_subtotal) {
            $fail('Spend at least $' . number_format((float) $promo->min_subtotal, 2) . ' to use this code.');
        }

        return ['promo' => $promo, 'discount' => self::discount($promo, $subtotal)];
    }

    public static function discount(PromoCode $promo, float $subtotal): float
    {
        $d = $promo->type === 'percent' ? $subtotal * (float) $promo->value / 100 : (float) $promo->value;
        if ($promo->type === 'percent' && $promo->max_discount !== null) {
            $d = min($d, (float) $promo->max_discount);
        }
        $d = min(round($d, 2), $subtotal);

        // Free is fine, but never a charge Stripe/PayPal would refuse.
        $left = round($subtotal - $d, 2);
        if ($left > 0 && $left < self::MIN_CHARGE) {
            $d = max(0.0, round($subtotal - self::MIN_CHARGE, 2));
        }

        return $d;
    }
}