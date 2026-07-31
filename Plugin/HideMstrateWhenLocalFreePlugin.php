<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Plugin;

use DepositoTrujillo\EnviaShipping\Model\LocalFreeConfig;
use Magento\Shipping\Model\Shipping;
use Psr\Log\LoggerInterface;

/**
 * When local free (Neiva) is quoted, drop mstrate paid table rates.
 * Kill-switch: carriers/dtlocalfree/hide_mstrate_when_local_free = 0 or active = 0.
 */
class HideMstrateWhenLocalFreePlugin
{
    public function __construct(
        private readonly LocalFreeConfig $localFreeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return Shipping
     */
    public function afterCollectRates(Shipping $subject, Shipping $result)
    {
        if (!$this->localFreeConfig->isActive()) {
            return $result;
        }
        if (!$this->localFreeConfig->hideMstrateWhenLocalFree()) {
            return $result;
        }

        $rateResult = $subject->getResult();
        if ($rateResult === null) {
            return $result;
        }

        $all = $rateResult->getAllRates();
        if ($all === []) {
            return $result;
        }

        $hasLocalFree = false;
        foreach ($all as $rate) {
            if ($rate->getCarrier() === 'dtlocalfree') {
                $hasLocalFree = true;
                break;
            }
        }

        if (!$hasLocalFree) {
            return $result;
        }

        $rateResult->reset();
        foreach ($all as $rate) {
            if ($rate->getCarrier() === 'mstrate') {
                continue;
            }
            $rateResult->append($rate);
        }

        $this->logger->debug('[EnviaShipping] removed mstrate rates (dtlocalfree present)');
        return $result;
    }
}
