<?php
// src/controllers/PaymentController.php

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/BigCommerceClient.php';
require_once __DIR__ . '/../services/RemitaClient.php';
require_once __DIR__ . '/../services/PaymentService.php';

class PaymentController
{
    public function handleInitiatePayment()
    {
        $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : null;
        // BigCommerce will pass the store hash when hitting your application frame/script entry point
        $storeHash = isset($_GET['store_hash']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['store_hash']) : null;

        if (!$orderId || !$storeHash) {
            http_response_code(400);
            die("Error: Missing parameters.");
        }

        try {
            // DYNAMIC LOOKUP: Fetch credentials from your central database instead of getenv()
            $credentials = Database::getMerchantCredentials($storeHash);
            if (!$credentials) {
                throw new Exception("Merchant store registration not found.");
            }

            // Instantiate BigCommerceClient using retrieved token
            $bcClient = new BigCommerceClient($storeHash, $credentials['bigcommerce_access_token']);
            $order = $bcClient->getOrder($orderId);

            $billingAddress = $bcClient->getOrderBillingAddress($orderId);
            if ($billingAddress) {
                $order['billing_address'] = $billingAddress;
            } else {
                throw new Exception("Could not retrieve billing information.");
            }

            // NEW FORMAT: Embed store_hash directly in the transaction payload identifier
            $paymentIdentifier = 'bc-' . $storeHash . '-' . $orderId . '-' . time();

            // Secure token inside the store's Metafields
            $bcClient->createOrderMetafield($orderId, 'current_payment_id', $paymentIdentifier);

            // Instantiate Remita client with dynamic credentials
            $remitaClient = new RemitaClient($credentials['remita_base_url'], $credentials['remita_secret_key']);
            $paymentService = new PaymentService($remitaClient);

            // Pass the global callback URL (this can still live in config or an environment string)
            $globalConfig = include __DIR__ . '/../../config.php';
            $checkoutUrl = $paymentService->initiatePayment($order, $paymentIdentifier, $globalConfig['callback_url']);

            header("Location: " . $checkoutUrl);
            exit;

        } catch (Exception $e) {
            http_response_code(500);
            echo "<h3>Checkout Initialization Error:</h3>";
            echo htmlspecialchars($e->getMessage());
        }
    }
}