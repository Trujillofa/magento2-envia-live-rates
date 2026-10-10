<?php
/**
 * Route a customer sentence toward Envia. This class does not call Envia
 * and does not invent a price. The intent id comes from EmbeddingGemma.
 */
declare(strict_types=1);

namespace DepositoTrujillo\EnviaShipping\Model\Intent;

final class ShippingQuestion
{
    private const CITIES = [
        'florencia' => 'Florencia',
        'ibagué' => 'Ibagué',
        'ibague' => 'Ibagué',
        'espinal' => 'Espinal',
        'neiva' => 'Neiva',
        'bogotá' => 'Bogotá',
        'bogota' => 'Bogotá',
    ];

    /**
     * @return array{action: string, intent: string, city: ?string, packages: ?int, price: null}
     */
    public function route(string $message, string $intent): array
    {
        if ($intent !== 'envia_live_rate') {
            return [
                'action' => 'not_shipping',
                'intent' => $intent,
                'city' => null,
                'packages' => null,
                'price' => null,
            ];
        }
        return [
            'action' => 'envia_live_rate',
            'intent' => $intent,
            'city' => $this->city($message),
            'packages' => $this->packages($message),
            'price' => null,
        ];
    }

    private function city(string $message): ?string
    {
        $folded = mb_strtolower($message);
        foreach (self::CITIES as $token => $label) {
            if (str_contains($folded, $token)) {
                return $label;
            }
        }
        return null;
    }

    private function packages(string $message): ?int
    {
        if (preg_match('/(\d+)\s*(bultos?|cajas?|unidades?)/iu', $message, $match) !== 1) {
            return null;
        }
        return (int) $match[1];
    }
}
