<?php
declare(strict_types=1);

namespace Remita\BigCommerce;

/**
 * Contract for the BigCommerce Management API client.
 *
 * Extracting this interface allows test stubs and alternative implementations
 * without coupling to the concrete HTTP client.
 */
interface BigCommerceClientInterface
{
    /**
     * Fetch a single order (v2).
     *
     * @return array<string,mixed>
     */
    public function getOrder(int $orderId): array;

    /**
     * Update an order's status / payment_status (v2).
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public function updateOrder(int $orderId, array $fields): array;

    /**
     * Create a metafield on an order (v3).
     *
     * @return array<string,mixed>
     */
    public function createOrderMetafield(int $orderId, string $key, string $value): array;

    /**
     * Fetch all metafields for an order (v3).
     *
     * @return array<string,mixed>
     */
    public function getOrderMetafields(int $orderId): array;

    /**
     * Fetch basic store information (v2).
     *
     * @return array<string,mixed>
     */
    public function getStoreInfo(): array;
}
