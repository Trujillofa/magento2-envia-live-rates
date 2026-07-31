<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Plugin;

use DepositoTrujillo\EnviaShipping\Model\Config;
use Magento\Shipping\Model\Shipping;
use Psr\Log\LoggerInterface;

/**
 * After all carriers quote, drop mstrate methods when Envia returned live rates.
 * Runs post-collection so carrier order does not matter.
 *
 * Kill-switch: carriers/envia/hide_mstrate_when_envia = 0 or active = 0.
 */
class HideMstrateWhenEnviaPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return Shipping
     */
    public function afterCollectRates(Shipping $subject, Shipping $result)
    {
        if (!$this->config->isActive()) {
            return $result;
        }
        if (!$this->config->hideMstrateWhenEnvia()) {
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

        $hasEnvia = false;
        foreach ($all as $rate) {
            if ($rate->getCarrier() === 'envia') {
                $hasEnvia = true;
                break;
            }
        }

        if (!$hasEnvia) {
            return $result;
        }

        $rateResult->reset();
        foreach ($all as $rate) {
            if ($rate->getCarrier() === 'mstrate') {
                continue;
            }
            $rateResult->append($rate);
        }

        $this->logger->debug('[EnviaShipping] removed mstrate rates (Envia present in result)');
        return $result;
    }
}
