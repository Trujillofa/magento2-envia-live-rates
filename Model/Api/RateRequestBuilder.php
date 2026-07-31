<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model\Api;

use DepositoTrujillo\EnviaShipping\Model\Config;
use Magento\Quote\Model\Quote\Address\RateRequest;

/**
 * Builds Envia /ship/rate payload pieces from Magento RateRequest + config.
 */
class RateRequestBuilder
{
    public function __construct(
        private readonly Config $config,
        private readonly Client $client
    ) {
    }

    /**
     * @return array{
     *   origin: array<string, string>,
     *   destination: array<string, string>,
     *   packages: list<array<string, mixed>>,
     *   dest_country: string
     * }|null
     */
    public function buildAddressesAndPackages(RateRequest $request, ?int $storeId = null): ?array
    {
        $origin = $this->config->getOriginAddress($storeId);
        if ($origin['street'] === '' || $origin['city'] === '' || $origin['postalCode'] === '') {
            return null;
        }
        // Envia rate API can return empty data for UTF-8 names/content on some paths
        $origin['name'] = $this->asciiSafe($origin['name'] !== '' ? $origin['name'] : 'Warehouse');
        $origin['street'] = $this->asciiSafe($origin['street']);
        $origin['city'] = $this->asciiSafe($origin['city']);

        $destCountry = strtoupper((string) ($request->getDestCountryId() ?: 'CO'));
        $destCity = $this->normalizeCityName(trim((string) $request->getDestCity()));
        $destPostcode = trim((string) $request->getDestPostcode());
        $destRegionCode = $this->resolveDestRegionCode($request);

        // Magento CO checkout often has no postcode field — do not require it for CO.
        if ($destCity === '') {
            return null;
        }
        if ($destCountry !== 'CO' && $destPostcode === '') {
            return null;
        }

        // Colombia: city field must be DANE code
        if ($destCountry === 'CO') {
            $origin = $this->resolveColombiaAddress($origin, $storeId);
            if ($origin === null) {
                return null;
            }
            $located = $this->client->locateColombiaCity($destCity, $destRegionCode, $storeId);
            // Retry with raw city (e.g. "Bogota, D.C.") if normalized failed
            if ($located === null) {
                $rawCity = trim((string) $request->getDestCity());
                if ($rawCity !== '' && $rawCity !== $destCity) {
                    $located = $this->client->locateColombiaCity($rawCity, $destRegionCode, $storeId);
                }
            }
            if ($located === null) {
                return null;
            }
            if ($destPostcode === '') {
                $destPostcode = $this->fallbackPostalCodeForDane($located['city']);
            }
            $destination = [
                'name' => 'Customer',
                'phone' => '3000000000',
                'street' => $this->asciiSafe(trim((string) ($request->getDestStreet() ?: 'Calle'))),
                'city' => $located['city'],
                'state' => $located['state'],
                'country' => 'CO',
                'postalCode' => $destPostcode,
            ];
        } else {
            $destination = [
                'name' => 'Customer',
                'phone' => '3000000000',
                'street' => trim((string) ($request->getDestStreet() ?: 'Street')),
                'city' => $destCity,
                'state' => $destRegionCode,
                'country' => $destCountry,
                'postalCode' => $destPostcode,
            ];
        }

        $packages = $this->buildPackages($request, $storeId);
        if ($packages === []) {
            return null;
        }

        return [
            'origin' => $origin,
            'destination' => $destination,
            'packages' => $packages,
            'dest_country' => $destCountry,
        ];
    }

    /**
     * @param array{
     *   name: string,
     *   phone: string,
     *   street: string,
     *   city: string,
     *   state: string,
     *   country: string,
     *   postalCode: string
     * } $origin
     * @return array<string, string>|null
     */
    private function resolveColombiaAddress(array $origin, ?int $storeId): ?array
    {
        // Already DANE?
        if (preg_match('/^\d{5,10}$/', $origin['city'])) {
            return $origin;
        }
        $located = $this->client->locateColombiaCity($origin['city'], $origin['state'], $storeId);
        if ($located === null) {
            return null;
        }
        $origin['city'] = $located['city'];
        $origin['state'] = $located['state'];
        return $origin;
    }

    private function resolveDestRegionCode(RateRequest $request): string
    {
        $code = strtoupper(trim((string) $request->getDestRegionCode()));
        // Magento directory often uses ISO-like codes: CO-CUN, CO-ANT
        if (str_starts_with($code, 'CO-') && strlen($code) >= 5) {
            $code = substr($code, 3);
        }
        if ($code !== '' && strlen($code) <= 3) {
            return $this->mapColombiaDepartment($code);
        }
        // Magento may only expose region name; map common CO departments
        $name = strtoupper(trim((string) ($request->getDestRegion() ?: $code)));
        return $this->mapColombiaDepartment($name);
    }

    /**
     * Normalize checkout city labels for Envia /locate (e.g. "Bogota, D.C." → "Bogota").
     */
    private function normalizeCityName(string $city): string
    {
        $city = $this->asciiSafe($city);
        $city = preg_replace('/\s*,\s*D\.?\s*C\.?\s*$/i', '', $city) ?? $city;
        $city = preg_replace('/\s+D\.?\s*C\.?\s*$/i', '', $city) ?? $city;
        return trim($city);
    }

    /**
     * When checkout omits postcode (common for CO), invent a stable 6-digit code
     * from the DANE municipal id so Envia rate API still accepts the address.
     */
    private function fallbackPostalCodeForDane(string $daneCity): string
    {
        // Known hubs
        $known = [
            '11001000' => '110111', // Bogota
            '05001000' => '050001', // Medellin
            '76001000' => '760001', // Cali
            '41001000' => '410010', // Neiva
            '08001000' => '080001', // Barranquilla
            '13001000' => '130001', // Cartagena
        ];
        if (isset($known[$daneCity])) {
            return $known[$daneCity];
        }
        // DANE is often 8 digits: use first 6 as postal-ish fallback
        $digits = preg_replace('/\D/', '', $daneCity) ?? '';
        if (strlen($digits) >= 6) {
            return substr($digits, 0, 6);
        }
        return '110111';
    }

    private function mapColombiaDepartment(string $name): string
    {
        $name = strtoupper(trim($this->asciiSafe($name)));
        // Canonical codes are Envia /locate state values (validated 2026-07-29).
        // Magento ISO (CO-SAN) and full region names map into those.
        $map = [
            // Magento ISO subdivision suffixes (after CO- strip)
            'ANT' => 'AN', 'CUN' => 'CN', 'VAC' => 'VC', 'ATL' => 'AT',
            'SAN' => 'SN', 'NSA' => 'NS', 'BOL' => 'BL', 'MAG' => 'MA',
            'CES' => 'CE', 'COR' => 'CO', 'NAR' => 'NA', 'CAU' => 'CU',
            'MET' => 'ME', 'RIS' => 'RI', 'CAL' => 'CL', 'QUI' => 'QU',
            'BOY' => 'BY', 'CAQ' => 'CA', 'PUT' => 'PU', 'CAS' => 'CS',
            'ARA' => 'AR', 'CHO' => 'CH', 'LAG' => 'LG', 'SUC' => 'SU',
            'HUI' => 'HU', 'TOL' => 'TO', 'DC' => 'DC',
            'GUV' => 'GA', 'VAU' => 'VA', 'VIC' => 'VI', 'SAP' => 'SA',
            // Full names
            'ANTIOQUIA' => 'AN',
            'ATLANTICO' => 'AT',
            'BOGOTA' => 'DC',
            'BOGOTA D.C.' => 'DC',
            'DISTRITO CAPITAL DE BOGOTA' => 'DC',
            'CUNDINAMARCA' => 'CN',
            'VALLE DEL CAUCA' => 'VC',
            'VALLE' => 'VC',
            'HUILA' => 'HU',
            'TOLIMA' => 'TO',
            'SANTANDER' => 'SN',
            'NORTE DE SANTANDER' => 'NS',
            'BOLIVAR' => 'BL',
            'MAGDALENA' => 'MA',
            'CESAR' => 'CE',
            'CORDOBA' => 'CO',
            'NARINO' => 'NA',
            'CAUCA' => 'CU',
            'META' => 'ME',
            'RISARALDA' => 'RI',
            'CALDAS' => 'CL',
            'QUINDIO' => 'QU',
            'BOYACA' => 'BY',
            'CAQUETA' => 'CA',
            'PUTUMAYO' => 'PU',
            'CASANARE' => 'CS',
            'ARAUCA' => 'AR',
            'CHOCO' => 'CH',
            'LA GUAJIRA' => 'LG',
            'SUCRE' => 'SU',
            'GUAVIARE' => 'GA',
            'VAUPES' => 'VA',
            'VICHADA' => 'VI',
            'AMAZONAS' => 'AM',
            'GUAINIA' => 'GU',
            'SAN ANDRES' => 'SA',
            // Envia canonical 2-letter codes (passthrough)
            'AN' => 'AN', 'AT' => 'AT', 'CN' => 'CN', 'DC' => 'DC', 'VC' => 'VC',
            'HU' => 'HU', 'TO' => 'TO', 'SN' => 'SN', 'NS' => 'NS', 'BL' => 'BL',
            'MA' => 'MA', 'CE' => 'CE', 'NA' => 'NA', 'CU' => 'CU', 'ME' => 'ME',
            'RI' => 'RI', 'CL' => 'CL', 'QU' => 'QU', 'BY' => 'BY', 'CA' => 'CA',
            'PU' => 'PU', 'CS' => 'CS', 'AR' => 'AR', 'CH' => 'CH', 'LG' => 'LG',
            'SU' => 'SU', 'GA' => 'GA', 'VA' => 'VA', 'VI' => 'VI', 'AM' => 'AM',
            'GU' => 'GU', 'SA' => 'SA', 'CO' => 'CO',
            // Legacy wrong codes we used earlier → correct Envia
            'ST' => 'SN', 'QD' => 'QU', 'CQ' => 'CA', 'GV' => 'GA',
            'VP' => 'VA', 'VD' => 'VI', 'GN' => 'GU',
        ];
        $key = preg_replace('/\s+/', ' ', $name) ?? $name;
        return $map[$key] ?? (strlen($name) === 2 ? $name : 'CN');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPackages(RateRequest $request, ?int $storeId): array
    {
        $weight = (float) $request->getPackageWeight();
        if ($weight <= 0) {
            return [];
        }

        $value = (float) $request->getPackageValue();
        if ($value <= 0) {
            $value = 10000.0;
        }

        $length = $this->config->getDefaultLengthCm($storeId);
        $width = $this->config->getDefaultWidthCm($storeId);
        $height = $this->config->getDefaultHeightCm($storeId);

        // Prefer first item dims if present on request items
        $items = $request->getAllItems();
        if (is_array($items) && $items !== []) {
            foreach ($items as $item) {
                if (!$item || $item->getParentItem()) {
                    continue;
                }
                $product = $item->getProduct();
                if (!$product) {
                    continue;
                }
                $l = (float) $product->getData('ts_dimensions_length')
                    ?: (float) $product->getData('length')
                    ?: (float) $product->getData('dim_length');
                $w = (float) $product->getData('ts_dimensions_width')
                    ?: (float) $product->getData('width')
                    ?: (float) $product->getData('dim_width');
                $h = (float) $product->getData('ts_dimensions_height')
                    ?: (float) $product->getData('height')
                    ?: (float) $product->getData('dim_height');
                if ($l > 0 && $w > 0 && $h > 0) {
                    $length = $l;
                    $width = $w;
                    $height = $h;
                    break;
                }
            }
        }

        return [[
            'type' => 'box',
            // ASCII-only: Envia / Magento Curl often return empty rates with UTF-8 content
            'content' => 'Mercancia ferreteria',
            'amount' => 1,
            'declaredValue' => round($value, 2),
            'lengthUnit' => 'CM',
            'weightUnit' => 'KG',
            'weight' => round($weight, 3),
            'dimensions' => [
                'length' => round($length, 2),
                'width' => round($width, 2),
                'height' => round($height, 2),
            ],
        ]];
    }

    /**
     * Strip accents for Envia address fields (API is sensitive to UTF-8 in some paths).
     */
    private function asciiSafe(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N', 'ü' => 'u', 'Ü' => 'U',
        ];
        return strtr($value, $map);
    }
}
