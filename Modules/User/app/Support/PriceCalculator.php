<?php

namespace Modules\User\Support;

use Modules\Admin\Models\Service;

/**
 * The one place a booking price is worked out. Prices are tax-exclusive.
 * Promo codes and tax settings plug in here in later phases; today both are zero.
 */
class PriceCalculator
{
    public static function quote(Service $service, ?string $country = null, ?string $region = null, ?string $promoCode = null): array
    {
        $isQuote = $service->price_type === 'quote' || $service->base_price === null;
        $subtotal = $isQuote ? 0.0 : (float) $service->base_price;

        $discount = 0.0; // Phase 5: promo codes
        $tax = 0.0;      // Phase 5+: admin tax settings per country / province

        return [
            'currency' => $service->currency,
            'is_quote' => $isQuote,
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discount, 2),
            'tax_total' => round($tax, 2),
            'total' => round(max($subtotal - $discount, 0) + $tax, 2),
        ];
    }
}