<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model\Hybrid;

/**
 * Request-scoped flag shared between Envia carrier and mstrate plugin.
 */
class QuoteContext
{
    private bool $enviaSucceeded = false;

    private string $lastReason = '';

    public function markEnviaSucceeded(): void
    {
        $this->enviaSucceeded = true;
    }

    public function enviaSucceeded(): bool
    {
        return $this->enviaSucceeded;
    }

    public function setLastReason(string $reason): void
    {
        $this->lastReason = $reason;
    }

    public function getLastReason(): string
    {
        return $this->lastReason;
    }

    public function reset(): void
    {
        $this->enviaSucceeded = false;
        $this->lastReason = '';
    }
}
