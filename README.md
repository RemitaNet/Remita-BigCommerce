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

    Customer places order on BigCommerce
            |
    public/index.php  →  BigCommerceAdapter::initiateCheckout()
            |
    Remita Hosted Checkout (customer pays)
            |
            +-- Browser return  →  public/callback.php  (optimistic UX update)
            +-- Remita webhook  →  public/webhook.php   (authoritative update)
                    |
            BigCommerce order status updated

A browser return is not enough to prove that money was successfully paid. The adapter therefore verifies the payment server-side with Remita before trusting it. The webhook is treated as a notification; Remita's status query is treated as the source of truth.

## 📋 What You Need

| Requirement | Why you need it |
|---|---|
| PHP 8.1+ | Runs the adapter |
| PHP ext-curl | Communicates with BigCommerce and Remita |
| BigCommerce store | Provides the customer order |
| BigCommerce app credentials | OAuth install and API access |
| BigCommerce access token | Reads orders and writes status updates |
| Remita merchant account | Receives customer payments |
| Remita Payment Engine access | Creates payment checkouts |
| Remita credentials | Authenticates API requests |
| Public HTTPS URL | Receives Remita webhooks |
| Writable `data/` directory | Stores idempotency records and credentials |

## 📦 Installation

### 1. Get the project

    git clone <repository-url>
    cd bigcommerce-remita

### 2. Check PHP

    php -v
    php -m | grep curl

### 3. Create your configuration

    cp config.php.example config.php

### 4. Create writable directories

    mkdir -p data/idempotency data/stores logs

## ⚙️ Configuration

Edit `config.php`:

| Key | Description |
|---|---|
| `bigcommerce_client_id` | From BigCommerce Dev Portal |
| `bigcommerce_client_secret` | From BigCommerce Dev Portal |
| `bigcommerce_redirect_uri` | Must match Dev Portal — points to `auth/install.php` |
| `bigcommerce_access_token` | Permanent store API token |
| `remita_base_url` | `https://remitademo.net` (demo) or `https://remita.net` (live) |
| `remita_secret_key` | Remita API secret key |
| `remita_webhook_secret` | HMAC-SHA256 secret registered in Remita dashboard |
| `app_url` | Base URL of this adapter (no trailing slash) |
| `data_dir` | Writable directory for idempotency records and store credentials |
| `log_dir` | Writable directory for log files |

All keys can alternatively be supplied as environment variables.

### BigCommerce App Installation

1. Deploy this adapter to a publicly reachable HTTPS server.
2. Create a BigCommerce app in the Dev Portal (https://developer.bigcommerce.com/).
3. Set the Auth Callback URL to `https://your-domain.com/auth/install.php`.
4. The merchant clicks Install → BigCommerce calls `auth/install.php` → OAuth token exchange → credentials stored in `data/stores/{storeHash}.json`.

### Remita Webhook Setup

In your Remita merchant dashboard, register the webhook URL:

    https://your-domain.com/public/webhook.php

Set the webhook secret to match `remita_webhook_secret` in your config. Remita will POST a JSON payload with an `X-Remita-Signature` HMAC-SHA256 header.

## 🟢 Payment Flow Details

### Initiation (`public/index.php`)

- BigCommerce calls `index.php` with `?order_id=N&store_hash=HASH`
- Adapter fetches the order from the BigCommerce Management API
- Generates a payment identifier: `bc-{storeHash}_{orderId}-{ts}-{rand}`
- Stores it as an order metafield (namespace: `remita_payment`, key: `current_payment_id`)
- POSTs the charge to Remita `/api/v1/payment/charge`
- Redirects the customer to the Remita hosted-checkout URL

### Browser Return (`public/callback.php`)

- Customer lands here after paying (or cancelling)
- Validates the identifier against the stored metafield
- Queries Remita for the current status
- Makes a best-effort update to the BigCommerce order
- Redirects to the storefront order-confirmation page

### Webhook (`public/webhook.php`)

- Verifies `X-Remita-Signature: HMAC-SHA256(rawBody, webhookSecret)` via timing-safe `hash_equals()`
- Maps Remita status to canonical: `success | pending | failed`
- Checks file-based idempotency — duplicate webhooks are silently skipped
- Enforces a regression guard — `success/paid/complete/captured` cannot be downgraded
- Updates BigCommerce order `status_id` and `payment_status`
- Stores `remita_transaction_id` metafield on success

## 🔔 Webhook Support

| Remita `status` / `paymentState` | Canonical |
|---|---|
| `00` or `APPROVED` | `success` |
| `01`, `02`, `03`, `04`, `09`, `45` or `PENDING` / `PROCESSING` | `pending` |
| anything else | `failed` |

Webhook body `status` field:

| Remita value | Canonical |
|---|---|
| `success`, `approved`, `completed` | `success` |
| `pending`, `processing`, `redirect` | `pending` |
| anything else | `failed` |

### BigCommerce Status IDs Used

| Canonical Status | `status_id` | `payment_status` |
|---|---|---|
| success | 10 (Awaiting Fulfillment) | captured |
| pending | 2 (Pending) | pending |
| failed | 1 (Incomplete) | failed |

## 🚀 Running Tests

    php tests/run.php

Tests are fully self-contained — no web server, no database, no live API keys required. External functions (cURL) are stubbed in `tests/bootstrap.php`.

Expected output:

    [PASS] AmountNormalizerTest -- 16 assertions, 0 failures
    [PASS] OrderMapperTest -- 20 assertions, 0 failures
    [PASS] PaymentIdentifierTest -- 16 assertions, 0 failures
    [PASS] PaymentStatusMapperTest -- 28 assertions, 0 failures
    [PASS] WebhookProcessorTest -- 18 assertions, 0 failures

    Done: 5 test files

Filter to one group:

    php tests/run.php --filter WebhookProcessor

## 📦 Packaging

    bash scripts/package-bigcommerce.sh

## 📚 Technical Reference

### Payment Identifier

Every initiated payment receives an identifier in this format:

    bc-{storeHash}_{orderId}-{ts}-{rand}

The `bc-` prefix scopes identifiers to this adapter within the Remita merchant account and keeps the store hash and order ID recoverable.

### Remita Status Codes

| Remita code | Canonical | Action |
|---|---|---|
| `00`, `01`, `025` | success | Update BigCommerce order to captured |
| `02` | pending | Return 200; wait for retry |
| `021` | processing | Return 200; wait for retry |
| `07`, `068`, `069`, `062`, `063` | failed | Mark as processed, return failure |
| Anything else | unknown | Log warning, return error |

### Logging

Logs are written to `log_dir` as append-only JSON files:

    logs/bigcommerce-YYYY-MM-DD.log
    logs/webhook-YYYY-MM-DD.log
    logs/initiate-YYYY-MM-DD.log

## 📝 Notes

- This is a hosted reference adapter, not a marketplace-ready BigCommerce app.
- No database is required — all state is file-based.
- The webhook handler verifies signatures and queries Remita before recording any payment.
- OAuth tokens are stored in `data/stores/` — add `data/` to `.gitignore`.
- `config.php` must never be committed — only `config.php.example` is tracked.
- Final order fulfilment logic should be wired by the host BigCommerce connector.

## 🔐 Security

- Webhook signature verified using `hash_equals()` (timing-safe comparison).
- All idempotency file writes are atomic: write to `.tmp` then `rename()`.
- Payment identifiers are verified against BigCommerce order metafields before processing.
- A terminal payment status (`success`, `paid`, `complete`, `captured`) can never be downgraded.
- Never commit `config.php` to Git.
- Keep API keys and webhook secrets outside source control.
- Never expose `data/` or `logs/` through the public web server.
- Keep TLS certificate and hostname verification enabled.
- Use HTTPS in production.
- Restrict filesystem permissions to the application/web-server user where possible.

## 📄 License

See the repository `LICENSE` file for details.
