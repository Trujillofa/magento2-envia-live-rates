<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model;

use DepositoTrujillo\EnviaShipping\Model\Local\CityMatcher;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * City-scoped free home delivery (Neiva by default).
 * Does not use Magento core freeshipping (which cannot filter by city).
 */
class LocalFreeCarrier extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'dtlocalfree';

    protected $_isFixed = true;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
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

        if (!$this->localFreeConfig->isActive($storeId)) {
            return false;
        }

        if (!$this->isDestCountryAllowed($request)) {
            return false;
        }

        $city = trim((string) $request->getDestCity());
        $allow = $this->localFreeConfig->getFreeCities($storeId);
        if (!$this->cityMatcher->matches($city, $allow)) {
            return false;
        }

        /** @var Result $result */
        $result = $this->rateResultFactory->create();
        $method = $this->rateMethodFactory->create();
        $method->setCarrier($this->_code);
        $method->setCarrierTitle((string) $this->getConfigData('title'));
        $method->setMethod('free');
        $method->setMethodTitle((string) ($this->getConfigData('name') ?: $this->localFreeConfig->getName($storeId)));
        $method->setPrice(0.0);
        $method->setCost(0.0);
        $result->append($method);

        return $result;
    }

    /**
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        return ['free' => (string) ($this->getConfigData('name') ?: 'Free local shipping')];
    }

    private function isDestCountryAllowed(RateRequest $request): bool
    {
        $country = strtoupper((string) ($request->getDestCountryId() ?: 'CO'));
        if ($country !== 'CO') {
            // Still allow if specific-country list includes it
            if ($this->getConfigData('sallowspecific') && $this->getConfigData('specificcountry')) {
                $allowed = array_map(
                    'strtoupper',
                    array_filter(array_map('trim', explode(',', (string) $this->getConfigData('specificcountry'))))
                );
                return in_array($country, $allowed, true);
            }
            return false;
        }
        return true;
    }
}
