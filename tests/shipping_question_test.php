<?php
declare(strict_types=1);

require dirname(__DIR__) . '/Model/Intent/ShippingQuestion.php';

use DepositoTrujillo\EnviaShipping\Model\Intent\ShippingQuestion;

$router = new ShippingQuestion();
$shipping = $router->route('¿Cuánto vale el envío a Florencia de 3 bultos?', 'envia_live_rate');
if ($shipping['action'] !== 'envia_live_rate' || $shipping['city'] !== 'Florencia' || $shipping['packages'] !== 3 || $shipping['price'] !== null) {
    fwrite(STDERR, "shipping route mismatch\n");
    exit(1);
}

$product = $router->route('precio del cemento gris', 'product_quote');
if ($product['action'] !== 'not_shipping' || $product['price'] !== null) {
    fwrite(STDERR, "product route mismatch\n");
    exit(1);
}

echo "ok\n";
