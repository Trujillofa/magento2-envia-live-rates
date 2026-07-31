<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * carriers/envia/* configuration reader.
 */
class Config
{
    private const XML_PREFIX = 'carriers/envia/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isActive(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'active',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isSandbox(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sandbox',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getApiToken(?int $storeId = null): string
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'api_token',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($raw === '') {
            return '';
        }
        try {
            return $this->encryptor->decrypt($raw);
        } catch (\Throwable) {
            // Allow plain token during first config before encrypt backend re-save
            return $raw;
        }
    }

    public function getBaseUrl(?int $storeId = null): string
    {
        return $this->isSandbox($storeId)
            ? 'https://api-test.envia.com'
            : 'https://api.envia.com';
    }

    /**
     * @return list<string>
     */
    public function getCarriersList(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'carriers_list',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $parts = preg_split('/[\s,;]+/', strtolower(trim($raw))) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    public function getParcelMaxKg(?int $storeId = null): float
    {
        $v = (float) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'parcel_max_kg',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $v > 0 ? $v : 30.0;
    }

    public function requireDimensions(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'require_dimensions',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getDefaultLengthCm(?int $storeId = null): float
    {
        return max(1.0, (float) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'default_length_cm',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getDefaultWidthCm(?int $storeId = null): float
    {
        return max(1.0, (float) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'default_width_cm',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getDefaultHeightCm(?int $storeId = null): float
    {
        return max(1.0, (float) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'default_height_cm',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getMarkupType(?int $storeId = null): string
    {
        $t = (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'markup_type',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return in_array($t, ['none', 'percent', 'fixed'], true) ? $t : 'none';
    }

    public function getMarkupValue(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'markup_value',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getHandlingFee(?int $storeId = null): float
    {
        return max(0.0, (float) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'handling_fee',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getCacheTtl(?int $storeId = null): int
    {
        $v = (int) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'cache_ttl',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return max(0, $v);
    }

    public function getTimeoutMs(?int $storeId = null): int
    {
        $v = (int) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'timeout_ms',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $v > 0 ? $v : 2500;
    }

    public function showEta(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'show_eta',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function oneMethodPerCarrier(?int $storeId = null): bool
    {
        // Default true when path missing (first deploy before config seed)
        $raw = $this->scopeConfig->getValue(
            self::XML_PREFIX . 'one_method_per_carrier',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($raw === null || $raw === '') {
            return true;
        }
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'one_method_per_carrier',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function hideMstrateWhenEnvia(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'hide_mstrate_when_envia',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isBackupRateActive(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'backup_rate_active',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getBackupRatePrice(?int $storeId = null): float
    {
        return max(0.0, (float) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'backup_rate_price',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getBackupRateTitle(?int $storeId = null): string
    {
        $t = (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'backup_rate_title',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $t !== '' ? $t : 'Tarifa de respaldo';
    }

    public function getTitle(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'title',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @return array{
     *   name: string,
     *   phone: string,
     *   street: string,
     *   city: string,
     *   state: string,
     *   country: string,
     *   postalCode: string
     * }
     */
    public function getOriginAddress(?int $storeId = null): array
    {
        return [
            'name' => (string) $this->scopeConfig->getValue(
                self::XML_PREFIX . 'origin_name',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
            'phone' => (string) $this->scopeConfig->getValue(
                self::XML_PREFIX . 'origin_phone',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
            'street' => (string) $this->scopeConfig->getValue(
                self::XML_PREFIX . 'origin_street',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
            'city' => (string) $this->scopeConfig->getValue(
                self::XML_PREFIX . 'origin_city',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
            'state' => strtoupper((string) $this->scopeConfig->getValue(
                self::XML_PREFIX . 'origin_state',
                ScopeInterface::SCOPE_STORE,
                $storeId
            )),
            'country' => strtoupper((string) ($this->scopeConfig->getValue(
                self::XML_PREFIX . 'origin_country',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ) ?: 'CO')),
            'postalCode' => (string) $this->scopeConfig->getValue(
                self::XML_PREFIX . 'origin_postal_code',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
        ];
    }
}
