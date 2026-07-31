<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model\Hybrid;

/**
 * Pure hybrid gate: parcel weight (+ optional dims) → Envia; else mstrate-only.
 * Unit-testable without Magento bootstrap.
 */
class QuoteEligibility
{
    /**
     * @param float               $totalWeightKg Sum of item weight * qty
     * @param float               $parcelMaxKg   Config threshold
     * @param bool                $requireDimensions
     * @param list<array{
     *   weight: float,
     *   qty: float,
     *   length?: float|null,
     *   width?: float|null,
     *   height?: float|null
     * }> $items
     * @return array{eligible: bool, reason: string}
     */
    public function evaluate(
        float $totalWeightKg,
        float $parcelMaxKg,
        bool $requireDimensions,
        array $items
    ): array {
        if ($totalWeightKg <= 0) {
            return ['eligible' => false, 'reason' => 'zero_or_missing_weight'];
        }

        if ($totalWeightKg > $parcelMaxKg) {
            return ['eligible' => false, 'reason' => 'over_parcel_max_kg'];
        }

        if ($items === []) {
            return ['eligible' => false, 'reason' => 'empty_cart'];
        }

        foreach ($items as $item) {
            $w = (float) ($item['weight'] ?? 0);
            $q = (float) ($item['qty'] ?? 0);
            if ($w <= 0 || $q <= 0) {
                return ['eligible' => false, 'reason' => 'item_missing_weight'];
            }
            if ($requireDimensions) {
                $l = (float) ($item['length'] ?? 0);
                $wi = (float) ($item['width'] ?? 0);
                $h = (float) ($item['height'] ?? 0);
                if ($l <= 0 || $wi <= 0 || $h <= 0) {
                    return ['eligible' => false, 'reason' => 'item_missing_dimensions'];
                }
            }
        }

        return ['eligible' => true, 'reason' => 'ok'];
    }
}
