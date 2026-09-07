<?php
declare(strict_types=1);

namespace Remita\BigCommerce;

use Remita\BigCommerce\Support\IdempotencyStoreInterface;
use Remita\BigCommerce\Support\LoggerInterface;
use Remita\BigCommerce\Support\OrderMapper;
use Remita\BigCommerce\Support\PaymentIdentifier;

/**
 * Orchestrates the BigCommerce -> Remita checkout initiation flow.
 *
 * Typical call sequence (called from public/index.php):
 *   1. Check idempotency cache — reuse existing checkoutUrl if present.
 *   2. Fetch the BigCommerce order and normalise it.
 *   3. Generate a unique payment identifier.
 *   4. Persist the identifier in BigCommerce order metafields (tamper-proof anchor).
 *   5. POST the Remita charge payload and obtain a hosted-checkout URL.
 *   6. Cache the result for idempotent retries.
 *   7. Return the checkoutUrl for a browser redirect.
 */
final class BigCommerceAdapter
{
    private const REMITA_CHARGE_PATH = '/api/v1/payment/charge';

    public function __construct(
        private readonly BigCommerceClient $bcClient,
        private readonly PaymentIdentifier $identifier,
        private readonly OrderMapper       $orderMapper,
        private readonly IdempotencyStoreInterface $idempotency,
        private readonly LoggerInterface           $logger,
        private readonly string            $remitaBaseUrl,
        private readonly string            $remitaSecretKey,
        private readonly string            $callbackBaseUrl,
    ) {}

    /**
     * Initiate a Remita checkout for a given BigCommerce order.
     *
     * Returns the Remita hosted-checkout URL to redirect the customer to.
     *
     * @param  string $storeHash BigCommerce store hash
     * @param  int    $orderId   BigCommerce order ID
     * @return string            Remita checkout URL
     * @throws \RuntimeException on any API or transport failure
     */
    public function initiateCheckout(string $storeHash, int $orderId): string
    {
        // Idempotency: if we already initiated for this order, reuse the URL.
        $idempotencyKey = "init:{$storeHash}:{$orderId}";
        $existing = $this->idempotency->get($idempotencyKey);
        if (is_array($existing) && isset($existing['checkoutUrl'])) {
            $this->logger->info('Returning cached checkout URL', [
                'storeHash' => $storeHash,
                'orderId'   => $orderId,
            ]);
            return (string) $existing['checkoutUrl'];
        }

        // Fetch and normalise the order.
        $rawOrder        = $this->bcClient->getOrder($orderId);
        $normalisedOrder = $this->orderMapper->normalize($rawOrder, $storeHash);

        // Generate a fresh payment identifier.
        $paymentId = $this->identifier->generate($storeHash, $orderId);

        // Persist identifier in BigCommerce order metafields (tamper-proof anchor).
        $this->bcClient->createOrderMetafield($orderId, 'current_payment_id', $paymentId);

        // Build the callback (return) URL.
        $returnUrl = rtrim($this->callbackBaseUrl, '/') . '/public/callback.php'
                   . '?paymentIdentifier=' . urlencode($paymentId);

        // Build the Remita payload.
        $payload = $this->orderMapper->toRemitaPayload($normalisedOrder, $paymentId, $returnUrl);

        // POST to Remita and obtain the checkout URL.
        $checkoutUrl = $this->postToRemita($payload);

        // Cache the result for idempotent retries.
        $this->idempotency->set($idempotencyKey, [
            'checkoutUrl'       => $checkoutUrl,
            'paymentIdentifier' => $paymentId,
            'initiatedAt'       => gmdate('c'),
        ]);

        $this->logger->info('Checkout initiated', [
            'storeHash'   => $storeHash,
            'orderId'     => $orderId,
            'paymentId'   => $paymentId,
            'checkoutUrl' => $checkoutUrl,
        ]);

        return $checkoutUrl;
    }

    /**
     * Send a charge request to the Remita API and return the checkoutUrl.
     *
     * Checks curl_errno() AND HTTP status code; throws \RuntimeException on failure.
     *
     * @param  array<string,mixed> $payload
     * @return string
     * @throws \RuntimeException on cURL error, non-200 HTTP, or missing checkoutUrl.
     */
    private function postToRemita(array $payload): string
    {
        $url = rtrim($this->remitaBaseUrl, '/') . self::REMITA_CHARGE_PATH;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'secretKey: ' . $this->remitaSecretKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw      = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Check cURL transport error first.
        if ($errno !== 0) {
            throw new \RuntimeException(
                "Remita cURL error ({$errno}): {$errMsg}"
            );
        }

        // Check HTTP status code.
        if ($httpCode !== 200) {
            throw new \RuntimeException(
                "Remita charge API returned HTTP {$httpCode}: {$raw}"
            );
        }

        $response = json_decode((string) $raw, true);
        if (!is_array($response) || empty($response['checkoutUrl'])) {
            throw new \RuntimeException(
                "Remita charge response missing checkoutUrl: {$raw}"
            );
        }

        return (string) $response['checkoutUrl'];
    }
}
