<?php
declare(strict_types=1);

/**
 * Remita webhook endpoint.
 *
 * Remita POSTs a JSON payload here when a payment status changes.
 * This is the authoritative handler — it verifies the HMAC-SHA256 signature,
 * maps the status, and updates the BigCommerce order.
 *
 * Configure this URL in your Remita dashboard:
 *   https://your-domain.com/public/webhook.php
 */

$root = dirname(__DIR__);
require_once $root . '/src/Support/AmountNormalizer.php';
require_once $root . '/src/Support/IdempotencyStore.php';
require_once $root . '/src/Support/LoggerService.php';
require_once $root . '/src/Support/OrderMapper.php';
require_once $root . '/src/Support/PaymentIdentifier.php';
require_once $root . '/src/Support/PaymentStatusMapper.php';
require_once $root . '/src/BigCommerceClient.php';
require_once $root . '/src/Webhook/WebhookProcessor.php';

$config = require $root . '/config.php';

// Only accept POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

// Read the raw body before any PHP stream manipulation.
$rawBody = (string) file_get_contents('php://input');

// Collect all request headers (Apache/Nginx compatible).
$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders() ?: [];
} else {
    foreach ($_SERVER as $serverKey => $serverValue) {
        if (str_starts_with((string) $serverKey, 'HTTP_')) {
            $name           = str_replace('_', '-', substr((string) $serverKey, 5));
            $headers[$name] = (string) $serverValue;
        }
    }
}

$logger = new \Remita\BigCommerce\Support\LoggerService($config['log_dir']);

try {
    // Factory closure: builds a BigCommerceClient scoped to the correct store.
    // The storeHash is parsed from the paymentIdentifier inside the processor.
    $bcClientFactory = static function (string $storeHash) use ($config): \Remita\BigCommerce\BigCommerceClient {
        return new \Remita\BigCommerce\BigCommerceClient($storeHash, $config['bigcommerce_access_token']);
    };

    $processor = new \Remita\BigCommerce\Webhook\WebhookProcessor(
        bcClientFactory: $bcClientFactory,
        identifier:      new \Remita\BigCommerce\Support\PaymentIdentifier(),
        statusMapper:    new \Remita\BigCommerce\Support\PaymentStatusMapper(),
        idempotency:     new \Remita\BigCommerce\Support\IdempotencyStore($config['data_dir']),
        logger:          $logger,
        webhookSecret:   $config['remita_webhook_secret'],
    );

    $processor->process($rawBody, $headers);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
} catch (\InvalidArgumentException $e) {
    // Bad payload — do not retry.
    $logger->warning('Webhook bad request', ['message' => $e->getMessage()]);
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Bad Request']);
} catch (\RuntimeException $e) {
    // Signature failure or upstream error — signal retry with 500.
    $logger->error('Webhook processing error', ['message' => $e->getMessage()]);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal Server Error']);
} catch (\Throwable $e) {
    $logger->error('Webhook unexpected error', ['message' => $e->getMessage()]);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal Server Error']);
}
