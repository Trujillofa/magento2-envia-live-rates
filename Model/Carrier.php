<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model;

use DepositoTrujillo\EnviaShipping\Model\Api\Client;
use DepositoTrujillo\EnviaShipping\Model\Api\RateRequestBuilder;
use DepositoTrujillo\EnviaShipping\Model\Cache\RateCache;
use DepositoTrujillo\EnviaShipping\Model\Hybrid\QuoteContext;
use DepositoTrujillo\EnviaShipping\Model\Hybrid\QuoteEligibility;
use DepositoTrujillo\EnviaShipping\Model\Local\CityMatcher;
use DepositoTrujillo\EnviaShipping\Model\Pricing\MarkupApplier;
use DepositoTrujillo\EnviaShipping\Model\Pricing\RateDeduper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * Magento carrier: live Envia rates for parcel carts (hybrid with mstrate).
 */
class Carrier extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'envia';

    protected $_isFixed = false;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        private readonly Config $config,
        private readonly QuoteEligibility $eligibility,
        private readonly QuoteContext $quoteContext,
        private readonly RateRequestBuilder $requestBuilder,
        private readonly Client $client,
        private readonly RateCache $rateCache,
        private readonly MarkupApplier $markupApplier,
        private readonly RateDeduper $rateDeduper,
        private readonly LocalFreeConfig $localFreeConfig,
        private readonly CityMatcher $cityMatcher,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * @return Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        $storeId = $request->getStoreId() !== null ? (int) $request->getStoreId() : null;

        if (!$this->config->isActive($storeId)) {
            $this->quoteContext->setLastReason('inactive');
            return false;
        }

        // Neiva (local free zone): do not quote paid Envia rates
        if ($this->localFreeConfig->isActive($storeId)
            && $this->cityMatcher->matches(
                trim((string) $request->getDestCity()),
                $this->localFreeConfig->getFreeCities($storeId)
            )
        ) {
            $this->quoteContext->setLastReason('local_free_zone');
            $this->_logger->debug('[EnviaShipping] skip: local free zone', [
                'city' => (string) $request->getDestCity(),
            ]);
            return false;
        }

        if ($this->config->getApiToken($storeId) === '') {
            $this->_logger->warning('[EnviaShipping] active but API token empty');
            $this->quoteContext->setLastReason('no_token');
            return false;
        }

        $itemsMeta = $this->extractItemsMeta($request);
        $totalWeight = (float) $request->getPackageWeight();
        $eval = $this->eligibility->evaluate(
            $totalWeight,
            $this->config->getParcelMaxKg($storeId),
            $this->config->requireDimensions($storeId),
            $itemsMeta
        );

        if (!$eval['eligible']) {
            $this->quoteContext->setLastReason($eval['reason']);
            $this->_logger->debug('[EnviaShipping] not eligible: ' . $eval['reason']);
            return false;
        }

        $built = $this->requestBuilder->buildAddressesAndPackages($request, $storeId);
        if ($built === null) {
            $this->quoteContext->setLastReason('address_or_package_build_failed');
            return false;
        }

        $carriers = $this->config->getCarriersList($storeId);
        if ($carriers === []) {
            $this->quoteContext->setLastReason('no_carriers_configured');
            return false;
        }

        $cacheKey = $this->rateCache->buildKey(
            $built['origin']['postalCode'] ?? '',
            $built['destination']['postalCode'] ?? '',
            (string) ($built['destination']['city'] ?? ''),
            $totalWeight,
            $carriers,
            $built['packages']
        );

        $ttl = $this->config->getCacheTtl($storeId);
        $cached = $this->rateCache->load($cacheKey);
        if ($cached !== null) {
            $rawRates = $cached;
            $this->_logger->debug('[EnviaShipping] cache hit', ['key' => $cacheKey]);
        } else {
            $rawRates = $this->fetchAllCarrierRates($built, $carriers, $storeId);
            if ($rawRates !== []) {
                $this->rateCache->save($cacheKey, $rawRates, $ttl);
            }
        }

        /** @var Result $result */
        $result = $this->rateResultFactory->create();

        if ($rawRates === []) {
            $this->quoteContext->setLastReason('no_rates');
            if ($this->config->isBackupRateActive($storeId)) {
                $result->append($this->createMethod(
                    'backup',
                    $this->config->getBackupRateTitle($storeId),
                    $this->config->getBackupRatePrice($storeId)
                ));
                // Backup alone does not hide mstrate
            }
            return $result;
        }

        if ($this->config->oneMethodPerCarrier($storeId)) {
            $rawRates = $this->rateDeduper->onePerCarrier($rawRates);
        }

        $markupType = $this->config->getMarkupType($storeId);
        $markupValue = $this->config->getMarkupValue($storeId);
        $handling = $this->config->getHandlingFee($storeId);
        $showEta = $this->config->showEta($storeId);

        foreach ($rawRates as $row) {
            $carrier = (string) ($row['carrier'] ?? 'envia');
            $service = (string) ($row['service'] ?? 'standard');
            $base = (float) ($row['totalPrice'] ?? 0);
            if ($base <= 0) {
                continue;
            }
            $price = $this->markupApplier->apply($base, $markupType, $markupValue, $handling);
            $label = $this->formatMethodTitle($row, $showEta);
            $methodCode = $this->sanitizeMethodCode($carrier . '_' . $service);
            $result->append($this->createMethod($methodCode, $label, $price));
        }

        if (!empty($result->getAllRates())) {
            $this->quoteContext->markEnviaSucceeded();
            $this->quoteContext->setLastReason('ok');
        } else {
            $this->quoteContext->setLastReason('rates_filtered_empty');
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        return [$this->_code => (string) $this->getConfigData('name')];
    }

    /**
     * @param array{
     *   origin: array<string, string>,
     *   destination: array<string, string>,
     *   packages: list<array<string, mixed>>
     * } $built
     * @param list<string> $carriers
     * @return list<array<string, mixed>>
     */
    private function fetchAllCarrierRates(array $built, array $carriers, ?int $storeId): array
    {
        $all = [];
        foreach ($carriers as $carrier) {
            $payload = [
                'origin' => $built['origin'],
                'destination' => $built['destination'],
                'packages' => $built['packages'],
                'shipment' => [
                    'type' => 1,
                    'carrier' => $carrier,
                ],
            ];
            $rows = $this->client->getRates($payload, $storeId);
            foreach ($rows as $row) {
                if (!isset($row['carrier'])) {
                    $row['carrier'] = $carrier;
                }
                $all[] = $row;
            }
        }

        usort($all, static function (array $a, array $b): int {
            return ((float) ($a['totalPrice'] ?? 0)) <=> ((float) ($b['totalPrice'] ?? 0));
        });

        return $all;
    }

    /**
     * @return list<array{weight: float, qty: float, length: ?float, width: ?float, height: ?float}>
     */
    private function extractItemsMeta(RateRequest $request): array
    {
        $out = [];
        $items = $request->getAllItems();
        if (!is_array($items)) {
            // Fallback: synthetic single package from request weight
            $w = (float) $request->getPackageWeight();
            if ($w > 0) {
                $out[] = ['weight' => $w, 'qty' => 1.0, 'length' => null, 'width' => null, 'height' => null];
            }
            return $out;
        }

        foreach ($items as $item) {
            if (!$item || $item->getParentItem()) {
                continue;
            }
            $qty = (float) $item->getQty();
            $weight = (float) $item->getWeight();
            $product = $item->getProduct();
            $l = $w = $h = null;
            if ($product) {
                $lVal = (float) $product->getData('ts_dimensions_length')
                    ?: (float) $product->getData('length');
                $wVal = (float) $product->getData('ts_dimensions_width')
                    ?: (float) $product->getData('width');
                $hVal = (float) $product->getData('ts_dimensions_height')
                    ?: (float) $product->getData('height');
                $l = $lVal > 0 ? $lVal : null;
                $w = $wVal > 0 ? $wVal : null;
                $h = $hVal > 0 ? $hVal : null;
            }
            $out[] = [
                'weight' => $weight,
                'qty' => $qty > 0 ? $qty : 1.0,
                'length' => $l,
                'width' => $w,
                'height' => $h,
            ];
        }

        if ($out === []) {
            $w = (float) $request->getPackageWeight();
            if ($w > 0) {
                $out[] = ['weight' => $w, 'qty' => 1.0, 'length' => null, 'width' => null, 'height' => null];
            }
        }

        return $out;
    }

    /**
     * Short customer-facing title: carrier brand + optional ETA.
     *
     * @param array<string, mixed> $row
     */
    private function formatMethodTitle(array $row, bool $showEta): string
    {
        $brand = trim((string) ($row['carrierDescription'] ?? ''));
        if ($brand === '') {
            $brand = (string) ($row['carrier'] ?? 'Envia');
            // interRapidisimo → InterRapidisimo
            $brand = preg_replace('/([a-z])([A-Z])/', '$1$2', $brand) ?? $brand;
            $brand = ucfirst($brand);
        }
        // Prefer short brand names
        $brandMap = [
            'servientrega' => 'Servientrega',
            'interrapidisimo' => 'InterRapidísimo',
            'coordinadora' => 'Coordinadora',
        ];
        $key = strtolower(preg_replace('/\s+/', '', $brand) ?? $brand);
        $key = strtolower((string) ($row['carrier'] ?? $key));
        if (isset($brandMap[$key])) {
            $brand = $brandMap[$key];
        }

        $title = $brand;
        if ($showEta && !empty($row['deliveryEstimate'])) {
            $title .= ' · ' . trim((string) $row['deliveryEstimate']);
        }
        return $title;
    }

    private function sanitizeMethodCode(string $code): string
    {
        $code = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $code) ?? $code);
        return substr($code, 0, 40) ?: 'standard';
    }

    private function createMethod(string $methodCode, string $title, float $price): Method
    {
        /** @var Method $method */
        $method = $this->rateMethodFactory->create();
        $method->setCarrier($this->_code);
        $method->setCarrierTitle((string) $this->getConfigData('title'));
        $method->setMethod($methodCode);
        $method->setMethodTitle($title);
        $method->setPrice($price);
        $method->setCost($price);
        return $method;
    }
}
