<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model\Pricing;

/**
 * Keep the cheapest rate per carrier (pure helper, unit-testable).
 */
class RateDeduper
{
    /**
     * @param list<array<string, mixed>> $rates
     * @return list<array<string, mixed>>
     */
    public function onePerCarrier(array $rates): array
    {
        $best = [];
        foreach ($rates as $row) {
            if (!is_array($row)) {
                continue;
            }
            $carrier = strtolower((string) ($row['carrier'] ?? ''));
            if ($carrier === '') {
                $carrier = 'unknown';
            }
            $price = (float) ($row['totalPrice'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            if (!isset($best[$carrier]) || $price < (float) $best[$carrier]['totalPrice']) {
                $best[$carrier] = $row;
            }
        }

        $out = array_values($best);
        usort($out, static function (array $a, array $b): int {
            return ((float) ($a['totalPrice'] ?? 0)) <=> ((float) ($b['totalPrice'] ?? 0));
        });
        return $out;
    }
}
