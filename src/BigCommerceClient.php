<?php
declare(strict_types=1);

namespace Remita\BigCommerce;

/**
 * Thin wrapper around the BigCommerce Management REST API (v2/v3).
 *
 * All HTTP failures — cURL transport errors and non-2xx responses — are
 * surfaced as \RuntimeException so callers get a consistent error type.
 *
 * BigCommerce Management API base:
 *   https://api.bigcommerce.com/stores/{store_hash}/v2/
 *
 * Authentication: X-Auth-Token header with the store's permanent access token.
 */
final class BigCommerceClient implements BigCommerceClientInterface
{
    private const API_BASE = 'https://api.bigcommerce.com/stores';

    public function __construct(
        private readonly string $storeHash,
        private readonly string $accessToken,
    ) {}

    // -------------------------------------------------------------------------
    // Orders  (v2)
    // -------------------------------------------------------------------------

    /**
     * Fetch a single order.
     *
     * GET /v2/orders/{order_id}
     *
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public function getOrder(int $orderId): array
    {
        return $this->request('GET', "/v2/orders/{$orderId}");
    }

    /**
     * Update an order's status and/or payment_status.
     *
     * PUT /v2/orders/{order_id}
     *
     * Common status IDs:
     *   2  = Pending
     *   10 = Awaiting Fulfillment  (payment captured)
     *   11 = Awaiting Shipment
     *
     * @param array<string,mixed> $fields  e.g. ['status_id' => 10, 'payment_status' => 'captured']
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public function updateOrder(int $orderId, array $fields): array
    {
        return $this->request('PUT', "/v2/orders/{$orderId}", $fields);
    }

    // -------------------------------------------------------------------------
    // Metafields  (v3)
    // -------------------------------------------------------------------------

    /**
     * Create a metafield on an order.
     *
     * POST /v3/orders/{order_id}/metafields
     *
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public function createOrderMetafield(int $orderId, string $key, string $value): array
    {
        return $this->request('POST', "/v3/orders/{$orderId}/metafields", [
            'permission_set' => 'app_only',
            'namespace'      => 'remita_payment',
            'key'            => $key,
            'value'          => $value,
        ]);
    }

    /**
     * Fetch all metafields for an order.
     *
     * GET /v3/orders/{order_id}/metafields
     *
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public function getOrderMetafields(int $orderId): array
    {
        return $this->request('GET', "/v3/orders/{$orderId}/metafields");
    }

    // -------------------------------------------------------------------------
    // Store  (v2)
    // -------------------------------------------------------------------------

    /**
     * Fetch basic store information (useful for OAuth verification).
     *
     * GET /v2/store
     *
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public function getStoreInfo(): array
    {
        return $this->request('GET', '/v2/store');
    }

    // -------------------------------------------------------------------------
    // HTTP transport
    // -------------------------------------------------------------------------

    /**
     * Execute an authenticated request against the BigCommerce API.
     *
     * Checks curl_errno() AND HTTP status code; throws \RuntimeException on
     * any transport or protocol failure.
     *
     * @param  array<string,mixed>|null $body  JSON-serialisable request body.
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    private function request(string $method, string $endpoint, ?array $body = null): array
    {
        $url = self::API_BASE . '/' . $this->storeHash . $endpoint;

        $headers = [
            'X-Auth-Token: ' . $this->accessToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $raw      = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Check cURL transport error first.
        if ($errno !== 0) {
            throw new \RuntimeException(
                "BigCommerce cURL error ({$errno}): {$errMsg} — {$method} {$url}"
            );
        }

        // Check HTTP status code.
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException(
                "BigCommerce API HTTP {$httpCode} on {$method} {$url}: {$raw}"
            );
        }

        // Empty body is valid for some PUT responses.
        if ($raw === '' || $raw === false) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                "BigCommerce API returned non-JSON on {$method} {$url}: {$raw}"
            );
        }

        return $decoded;
    }
}
