<?php
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class MarkupType implements OptionSourceInterface
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'none', 'label' => __('None (raw carrier price)')],
            ['value' => 'percent', 'label' => __('Percent surcharge')],
            ['value' => 'fixed', 'label' => __('Fixed COP surcharge')],
        ];
    }
}
