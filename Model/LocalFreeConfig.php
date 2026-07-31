<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * carriers/dtlocalfree/* configuration reader.
 */
class LocalFreeConfig
{
    private const XML_PREFIX = 'carriers/dtlocalfree/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
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

    public function getTitle(?int $storeId = null): string
    {
        $v = (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'title',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $v !== '' ? $v : 'Local free delivery';
    }

    public function getName(?int $storeId = null): string
    {
        $v = (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'name',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $v !== '' ? $v : 'Free local shipping';
    }

    /**
     * @return list<string>
     */
    public function getFreeCities(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'free_cities',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if (trim($raw) === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    public function hideMstrateWhenLocalFree(?int $storeId = null): bool
    {
        // Default true when flag missing
        $path = self::XML_PREFIX . 'hide_mstrate_when_local_free';
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '') {
            return true;
        }
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
