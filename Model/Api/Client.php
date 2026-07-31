<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model\Api;

use DepositoTrujillo\EnviaShipping\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Thin Envia Shipping API client (rate + locate).
 */
class Client
{
    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Resolve Colombian city name → DANE code via POST /locate (no auth).
     *
     * @return array{city: string, name: string, state: string}|null
     */
    public function locateColombiaCity(string $city, string $state, ?int $storeId = null): ?array
    {
        $city = trim($city);
        $state = strtoupper(trim($state));
        if ($city === '' || $state === '') {
            return null;
        }

        $url = rtrim($this->config->getBaseUrl($storeId), '/') . '/locate';
        $body = [
            'city' => $city,
            'state' => $state,
            'country' => 'CO',
        ];

        $response = $this->request('POST', $url, $body, false, $storeId);
        if ($response === null) {
            return null;
        }

        // Success shape: {city: "11001000", name: "BOGOTA", state: "CN"}
        if (!empty($response['city']) && preg_match('/^\d{5,10}$/', (string) $response['city'])) {
            return [
                'city' => (string) $response['city'],
                'name' => (string) ($response['name'] ?? $city),
                'state' => (string) ($response['state'] ?? $state),
            ];
        }

        // Sometimes wrapped
        if (!empty($response['data']['city']) && preg_match('/^\d{5,10}$/', (string) $response['data']['city'])) {
            $d = $response['data'];
            return [
                'city' => (string) $d['city'],
                'name' => (string) ($d['name'] ?? $city),
                'state' => (string) ($d['state'] ?? $state),
            ];
        }

        $this->logger->warning('[EnviaShipping] locate failed', [
            'city' => $city,
            'state' => $state,
            'response' => $response,
        ]);
        return null;
    }

    /**
     * Quote one carrier: POST /ship/rate/
     *
     * @param array<string, mixed> $payload Full Envia rate body including shipment.carrier
     * @return list<array<string, mixed>>
     */
    public function getRates(array $payload, ?int $storeId = null): array
    {
        $url = rtrim($this->config->getBaseUrl($storeId), '/') . '/ship/rate/';
        $response = $this->request('POST', $url, $payload, true, $storeId);
        if ($response === null) {
            return [];
        }

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }

        // Normalize to list of rate rows
        $out = [];
        foreach ($data as $row) {
            if (is_array($row) && isset($row['totalPrice'])) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function request(
        string $method,
        string $url,
        array $body,
        bool $auth,
        ?int $storeId
    ): ?array {
        $timeoutMs = $this->config->getTimeoutMs($storeId);
        $timeoutSec = max(1, (int) ceil($timeoutMs / 1000));

        try {
            /** @var Curl $curl */
            $curl = $this->curlFactory->create();
            $curl->setTimeout($timeoutSec);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Accept', 'application/json');
            // Cloudflare blocks empty/generic client signatures (error 1010)
            $curl->addHeader(
                'User-Agent',
                'DepositoTrujillo-EnviaShipping/1.0 (+https://www.depositotrujillo.co)'
            );
            if ($auth) {
                $token = $this->config->getApiToken($storeId);
                if ($token === '') {
                    $this->logger->error('[EnviaShipping] missing API token');
                    return null;
                }
                $curl->addHeader('Authorization', 'Bearer ' . $token);
            }

            $jsonBody = $this->json->serialize($body);
            if (strtoupper($method) === 'POST') {
                $curl->post($url, $jsonBody);
            } else {
                $curl->get($url);
            }

            $status = (int) $curl->getStatus();
            $raw = (string) $curl->getBody();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('[EnviaShipping] HTTP error', [
                    'url' => $url,
                    'status' => $status,
                    'body' => mb_substr($raw, 0, 500),
                ]);
                return null;
            }

            if ($raw === '') {
                return null;
            }

            /** @var array<string, mixed> $decoded */
            $decoded = $this->json->unserialize($raw);
            return $decoded;
        } catch (\Throwable $e) {
            $this->logger->error('[EnviaShipping] request exception: ' . $e->getMessage(), [
                'url' => $url,
            ]);
            return null;
        }
    }
}
