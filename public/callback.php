<?php
declare(strict_types=1);

/**
 * Browser return URL handler.
 *
 * Remita redirects the customer here after checkout (success or failure).
 * This script:
 *   1. Extracts and validates the paymentIdentifier from the query string.
 *   2. Parses it to get storeHash + orderId.
 *   3. Verifies the identifier against the metafield stored at initiation.
 *   4. Queries Remita to confirm the payment status.
 *   5. Updates the BigCommerce order accordingly.
 *   6. Redirects the customer to the storefront order-confirmation page.
 *
 * NOTE: This is a browser-facing redirect handler, not a webhook. The
 * authoritative status update comes from public/webhook.php. This handler
 * provides an optimistic immediate update for the customer experience.
 */

$root = dirname(__DIR__);
require_once $root . '/src/Support/AmountNormalizer.php';
require_once $root . '/src/Support/IdempotencyStore.php';
require_once $root . '/src/Support/LoggerService.php';
require_once $root . '/src/Support/OrderMapper.php';
require_once $root . '/src/Support/PaymentIdentifier.php';
require_once $root . '/src/Support/PaymentStatusMapper.php';
require_once $root . '/src/BigCommerceClient.php';

$config = require $root . '/config.php';

$rawIdentifier = $_GET['paymentIdentifier'] ?? '';
if ($rawIdentifier === '') {
    http_response_code(400);
    echo 'Missing paymentIdentifier parameter.';
    exit;
}

$logger     = new \Remita\BigCommerce\Support\LoggerService($config['log_dir']);
$identifier = new \Remita\BigCommerce\Support\PaymentIdentifier();
$mapper     = new \Remita\BigCommerce\Support\PaymentStatusMapper();

try {
    // 1. Parse the identifier.
    $parsed    = $identifier->parse($rawIdentifier);
    $storeHash = $parsed['storeHash'];
    $orderId   = $parsed['orderId'];

    $bcClient = new \Remita\BigCommerce\BigCommerceClient($storeHash, $config['bigcommerce_access_token']);

    // 2. Verify metafield anchor — confirms this identifier was set by us at initiation.
    $metaResponse = $bcClient->getOrderMetafields($orderId);
    $metaList     = $metaResponse['data'] ?? $metaResponse; // v3 wraps in 'data'
    $anchorFound  = false;

    if (is_array($metaList)) {
        foreach ($metaList as $meta) {
            if (
                isset($meta['namespace'], $meta['key'], $meta['value'])
                && $meta['namespace'] === 'remita_payment'
                && $meta['key']       === 'current_payment_id'
                && $meta['value']     === $rawIdentifier
            ) {
                $anchorFound = true;
                break;
            }
        }
    }

    if (!$anchorFound) {
        $logger->warning('Callback metafield anchor not found', [
            'paymentIdentifier' => $rawIdentifier,
            'orderId'           => $orderId,
        ]);
        // Do not reveal the reason to the browser; redirect to a neutral page.
        header('Location: https://store-' . urlencode($storeHash) . '.mybigcommerce.com/');
        exit;
    }

    // 3. Query Remita for the current payment status.
    $remitaStatus = queryRemitaStatus($rawIdentifier, $config);
    $canonical    = $mapper->fromApiResponse($remitaStatus);
    $transactionId = (string) ($remitaStatus['transactionId'] ?? $remitaStatus['rrr'] ?? $rawIdentifier);

    // 4. Apply the status to the BigCommerce order (best-effort; webhook is authoritative).
    if ($canonical === 'success') {
        $bcClient->updateOrder($orderId, [
            'status_id'      => 10,   // Awaiting Fulfillment
            'payment_status' => 'captured',
        ]);
        $bcClient->createOrderMetafield($orderId, 'remita_transaction_id', $transactionId);
    } elseif ($canonical === 'pending') {
        $bcClient->updateOrder($orderId, [
            'status_id'      => 2,    // Pending
            'payment_status' => 'pending',
        ]);
    }
    // For 'failed' we leave the order as-is and let the webhook handle it.

    $logger->info('Callback processed', [
        'orderId'         => $orderId,
        'canonical'       => $canonical,
        'transactionId'   => $transactionId,
    ]);

    // 5. Redirect customer back to storefront.
    $storefrontBase = 'https://store-' . urlencode($storeHash) . '.mybigcommerce.com';
    $destination    = $canonical === 'success'
        ? $storefrontBase . '/checkout/order-confirmation'
        : $storefrontBase . '/checkout';

    header('Location: ' . $destination);
    exit;
} catch (\Throwable $e) {
    $logger->error('Callback error', ['message' => $e->getMessage()]);
    http_response_code(500);
    echo 'An error occurred processing your payment return. Please contact support.';
    exit;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Query the Remita payment status API.
 *
 * @param  array<string,mixed> $config
 * @return array<string,mixed>
 * @throws \RuntimeException on cURL or HTTP failure.
 */
function queryRemitaStatus(string $paymentIdentifier, array $config): array
{
    $url = rtrim($config['remita_base_url'], '/') . '/api/v1/payment/query/' . urlencode($paymentIdentifier);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPGET        => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'secretKey: ' . $config['remita_secret_key'],
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw      = curl_exec($ch);
    $errno    = curl_errno($ch);
    $errMsg   = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new \RuntimeException("Remita status query cURL error ({$errno}): {$errMsg}");
    }

    if ($httpCode !== 200) {
        throw new \RuntimeException("Remita status query HTTP {$httpCode}: {$raw}");
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new \RuntimeException("Remita status query returned non-JSON: {$raw}");
    }

    return $decoded;
}
