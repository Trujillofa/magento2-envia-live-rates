<?php
/**
 * Magento 2 — Envia.com live checkout rates (multi-carrier).
 *
 * @copyright Copyright © Depósito Trujillo and contributors
 * @license   MIT
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'DepositoTrujillo_EnviaShipping',
    __DIR__
);
