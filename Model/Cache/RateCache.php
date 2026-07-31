<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model\Cache;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;

class RateCache
{
    public const CACHE_TAG = 'envia_shipping_rates';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Json $json
    ) {
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function load(string $key): ?array
    {
        $raw = $this->cache->load($key);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            $data = $this->json->unserialize($raw);
            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<array<string, mixed>> $rates
     */
    public function save(string $key, array $rates, int $ttl): void
    {
        if ($ttl <= 0) {
            return;
        }
        $this->cache->save(
            $this->json->serialize($rates),
            $key,
            [self::CACHE_TAG],
            $ttl
        );
    }

    /**
     * @param list<string> $carriers
     * @param array<string, mixed> $packageFingerprint
     */
    public function buildKey(
        string $originPostal,
        string $destPostal,
        string $destCity,
        float $weight,
        array $carriers,
        array $packageFingerprint
    ): string {
        $parts = [
            'envia_rate',
            $originPostal,
            $destPostal,
            $destCity,
            (string) round($weight, 3),
            implode(',', $carriers),
            md5($this->json->serialize($packageFingerprint)),
        ];
        return implode('|', $parts);
    }
}
