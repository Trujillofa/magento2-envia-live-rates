<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model\Local;

/**
 * Pure city normalizer + allow-list match for local free shipping zones.
 * Unit-testable without Magento bootstrap.
 */
class CityMatcher
{
    /**
     * Normalize city labels for comparison (ASCII, lower, strip D.C. / trailing department).
     */
    public function normalize(string $city): string
    {
        $city = trim($city);
        if ($city === '') {
            return '';
        }
        $city = $this->asciiFold($city);
        $city = strtolower($city);
        // "Bogota, D.C." / "Neiva, Huila" → take first segment when comma present
        if (str_contains($city, ',')) {
            $city = trim(explode(',', $city, 2)[0]);
        }
        $city = preg_replace('/\s+d\.?\s*c\.?\s*$/i', '', $city) ?? $city;
        $city = preg_replace('/\s+/', ' ', $city) ?? $city;
        return trim($city);
    }

    /**
     * @param list<string> $allowList Raw config cities (e.g. ["neiva", "Neiva "])
     */
    public function matches(string $city, array $allowList): bool
    {
        $needle = $this->normalize($city);
        if ($needle === '') {
            return false;
        }
        foreach ($allowList as $allowed) {
            $hay = $this->normalize((string) $allowed);
            if ($hay !== '' && $hay === $needle) {
                return true;
            }
        }
        return false;
    }

    private function asciiFold(string $s): string
    {
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($trans === false || $trans === '') {
            // Fallback strip non-ascii
            return preg_replace('/[^\x20-\x7E]/', '', $s) ?? $s;
        }
        return $trans;
    }
}
