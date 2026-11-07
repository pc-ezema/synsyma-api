<?php

namespace Modules\User\Support;

use Modules\Admin\Models\Service;
use Modules\Admin\Support\Promos;

/**
 * The one place a booking price is worked out. Prices are tax-exclusive.
 * Promo codes apply to the booking itself (never to extra hours). Tax settings plug in later.
 */
class PriceCalculator
{
    /** $promoCode needs $userId (limits are per customer). A bad code throws a ValidationException on "promo_code". */
    public static function quote(Service $service, ?string $country = null, ?string $region = null, ?string $promoCode = null, ?string $userId = null): array
    {
        $isQuote = $service->price_type === 'quote' || $service->base_price === null;
        $subtotal = $isQuote ? 0.0 : (float) $service->base_price;

        $discount = 0.0;
        $promo = null;
        if ($code = Promos::normalize($promoCode)) {
            if ($isQuote) {
                throw \Illuminate\Validation\ValidationException::withMessages(['promo_code' => 'Codes cannot be used on services priced by quote.']);
            }
            $checked = Promos::check($code, (string) $userId, $subtotal);
            $discount = $checked['discount'];
            $promo = $checked['promo'];
        }
        $tax = 0.0; // admin tax settings per country / province come later

        return [
            'currency' => $service->currency,
            'is_quote' => $isQuote,
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discount, 2),
            'tax_total' => round($tax, 2),
            'total' => round(max($subtotal - $discount, 0) + $tax, 2),
            'promo_code' => $promo?->code,
            'promo' => $promo ? ['code' => $promo->code, 'description' => $promo->description, 'label' => $promo->valueLabel()] : null,
        ];
    }
}