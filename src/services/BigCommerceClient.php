<?php

class BigCommerceClient
{
    private string $storeHash;
    private string $accessToken;

    public function __construct(string $storeHash, string $accessToken)
    {
        $this->storeHash = $storeHash;
        $this->accessToken = $accessToken;
    }

    /**
     * Base API URL for BigCommerce
     */
    private function baseUrl(): string
    {
        return "https://api.bigcommerce.com/stores/{$this->storeHash}";
    }

    /**
     * Generic request handler
     */
    private function request(string $method, string $endpoint, array $body = null)
    {
        $url = $this->baseUrl() . $endpoint;

        $ch = curl_init($url);

        $headers = [
            "X-Auth-Token: {$this->accessToken}",
            "Accept: application/json",
            "Content-Type: application/json"
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new Exception("BigCommerce cURL error: " . curl_error($ch));
        }

        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            throw new Exception("BigCommerce API error: " . $response);
        }

        return $decoded;
    }

    /**
     * Get a single order
     */
    public function getOrder(int $orderId) {
        return $this->request("GET", "/v2/orders/{$orderId}");
    }

    /**
     * Get order billing address
     */
    public function getOrderBillingAddress(int $orderId)
    {
        $response = $this->request("GET", "/orders/{$orderId}/addresses");

        return $response[0] ?? null;
    }

    /**
     * Mark order as paid (after Remita success)
     */
    public function markOrderAsPaid(int $orderId, string $transactionId)
    {
        return $this->request("POST", "/v3/orders/{$orderId}/transactions", [
            "event" => "capture",
            "method" => "card",
            "amount" => null,
            "currency" => "NGN",
            "transaction_id" => $transactionId
        ]);
    }

    /**
     * Update order status (backup method if needed)
     */
    public function updateOrderStatus(int $orderId, int $statusId)
    {
        return $this->request("PUT", "/orders/{$orderId}", [
            "status_id" => $statusId
        ]);
    }

    /**
     * Get store info (useful for debugging/install verification)
     */
    public function getStoreInfo()
    {
        return $this->request("GET", "/store");
    }

    public function createOrderMetafield(int $orderId, string $key, string $value)
    {
        return $this->request("POST", "/v3/orders/{$orderId}/metafields", [
            "permission_set" => "app_only",
            "namespace" => "remita_payment_plugin",
            "key" => $key,
            "value" => $value
        ]);
    }

    /**
     * Retrieve metafields for an order to search for your payment identifier
     */
    public function getOrderMetafields(int $orderId)
    {
        return $this->request("GET", "/v3/orders/{$orderId}/metafields");
    }
}
