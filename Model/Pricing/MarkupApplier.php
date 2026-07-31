<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model\Pricing;

/**
 * Pure pricing helper — reimplements Envia Rate Rules markup for Magento.
 * Unit-testable without Magento bootstrap.
 */
class MarkupApplier
{
    /**
     * Apply markup and optional handling fee to a carrier total.
     *
     * @param float  $basePrice   Carrier totalPrice
     * @param string $markupType  none|percent|fixed
     * @param float  $markupValue Percent (10 = +10%) or fixed COP
     * @param float  $handlingFee Extra COP added after markup
     */
    public function apply(
        float $basePrice,
        string $markupType,
        float $markupValue,
        float $handlingFee = 0.0
    ): float {
        if ($basePrice < 0) {
            $basePrice = 0.0;
        }

        $price = match ($markupType) {
            'percent' => $basePrice * (1.0 + max(0.0, $markupValue) / 100.0),
            'fixed' => $basePrice + max(0.0, $markupValue),
            default => $basePrice,
        };

        $price += max(0.0, $handlingFee);

        // COP storefront: whole pesos
        return round($price, 0);
    }
}
