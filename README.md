# BigCommerce + Remita Checkout Adapter

A production-grade PHP 8.1 adapter that integrates the **Remita hosted-checkout** payment gateway into **BigCommerce** stores. No database required — all state is file-based.

---

## Status

Implemented as a source adapter.

## Integration Details

| Field | Value |
|---|---|
| **Integration mode** | Webhook + Redirect |
| **SDK** | PHP 8.1 |
| **Platform** | BigCommerce |

## ✨ What It Does

- 🏷️ Builds a BigCommerce-scoped `paymentIdentifier`
- 🔗 Initiates Remita hosted-checkout redirect payments
- 🔔 Receives and verifies Remita webhooks via HMAC-SHA256
- ✅ Confirms payment status with Remita before trusting the browser return
- 💾 Updates BigCommerce order status and payment status
- 🔁 Prevents duplicate processing with file-based idempotency
- 🛡️ Enforces a regression guard — terminal success states can't be downgraded
- 📊 Keeps structured JSON logs for troubleshooting

## 📁 Repository Shape

    integrations/commerce-platforms/bigcommerce/
    ├── auth/
    │   └── install.php                  OAuth install callback (file-based, no DB)
    ├── public/
    │   ├── index.php                    Payment initiation entry point
    │   ├── callback.php                 Browser return URL (customer redirect)
    │   └── webhook.php                  Remita webhook endpoint (authoritative)
    ├── src/
    │   ├── BigCommerceAdapter.php       Orchestrates checkout initiation
    │   ├── BigCommerceClient.php        BigCommerce Management API v2/v3 (cURL)
    │   ├── BigCommerceClientInterface.php
    │   ├── Webhook/
    │   │   └── WebhookProcessor.php     Processes Remita webhooks
    │   └── Support/
    │       ├── AmountNormalizer.php     NGN ↔ kobo conversion
    │       ├── IdempotencyStore.php     File-based idempotency (data/idempotency/)
    │       ├── IdempotencyStoreInterface.php
    │       ├── LoggerService.php        Append-only JSON file logger
    │       ├── LoggerInterface.php
    │       ├── OrderMapper.php          BigCommerce order → Remita payload
    │       ├── PaymentIdentifier.php    bc-{storeHash}_{orderId}-{ts}-{rand}
    │       └── PaymentStatusMapper.php  Remita codes → canonical status
    ├── tests/
    ├── config.php.example
    └── README.md

## 🚀 How It Works
