<?php
declare(strict_types=1);

/**
 * Payment initiation entry point.
 *
 * Expects query parameters:
 *   order_id   int     BigCommerce order ID
 *   store_hash string  BigCommerce store hash
 *
 * Loads config, wires up the adapter, and redirects the customer to Remita checkout.
 */

$root = dirname(__DIR__);
require_once $root . '/src/Support/AmountNormalizer.php';
require_once $root . '/src/Support/IdempotencyStore.php';
require_once $root . '/src/Support/LoggerService.php';
require_once $root . '/src/Support/OrderMapper.php';
require_once $root . '/src/Support/PaymentIdentifier.php';
require_once $root . '/src/Support/PaymentStatusMapper.php';
require_once $root . '/src/BigCommerceClient.php';
require_once $root . '/src/BigCommerceAdapter.php';

$config = require $root . '/config.php';

$orderId   = isset($_GET['order_id'])   ? (int)    $_GET['order_id']                                   : 0;
$storeHash = isset($_GET['store_hash']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['store_hash'])    : '';

if ($orderId <= 0 || $storeHash === '') {
    http_response_code(400);
    echo 'Missing or invalid order_id / store_hash parameters.';
    exit;
}

try {
    $bcClient   = new \Remita\BigCommerce\BigCommerceClient($storeHash, $config['bigcommerce_access_token']);
    $normalizer = new \Remita\BigCommerce\Support\AmountNormalizer();
    $adapter    = new \Remita\BigCommerce\BigCommerceAdapter(
        bcClient:        $bcClient,
        identifier:      new \Remita\BigCommerce\Support\PaymentIdentifier(),
        orderMapper:     new \Remita\BigCommerce\Support\OrderMapper($normalizer),
        idempotency:     new \Remita\BigCommerce\Support\IdempotencyStore($config['data_dir']),
        logger:          new \Remita\BigCommerce\Support\LoggerService($config['log_dir']),
        remitaBaseUrl:   $config['remita_base_url'],
        remitaSecretKey: $config['remita_secret_key'],
        callbackBaseUrl: $config['app_url'],
    );

    $checkoutUrl = $adapter->initiateCheckout($storeHash, $orderId);

    header('Location: ' . $checkoutUrl);
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[BigCommerce-Remita] Checkout init error: ' . $e->getMessage());
    echo 'Payment initialisation failed. Please try again or contact support.';
    exit;
}
