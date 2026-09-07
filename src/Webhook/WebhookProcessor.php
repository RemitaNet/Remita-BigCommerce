<?php
declare(strict_types=1);

namespace Remita\BigCommerce\Webhook;

use Remita\BigCommerce\BigCommerceClientInterface;
use Remita\BigCommerce\Support\IdempotencyStoreInterface;
use Remita\BigCommerce\Support\LoggerInterface;
use Remita\BigCommerce\Support\PaymentIdentifier;
use Remita\BigCommerce\Support\PaymentStatusMapper;

/**
 * Processes inbound Remita payment webhooks.
 *
 * Responsibilities:
 *   1. Verify HMAC-SHA256 signature from X-Remita-Signature header.
 *   2. Decode and validate the JSON payload.
 *   3. Map Remita status to a canonical value via PaymentStatusMapper.
 *   4. Reject replays via file-based IdempotencyStore (keyed by transactionId + status).
 *   5. Enforce no-downgrade: never move a paid order back to pending/failed.
 *   6. Update BigCommerce order status accordingly.
 *   7. Mark the idempotency record AFTER the successful update.
 *
 * The BigCommerceClient is constructed per-webhook using a factory closure so
 * the per-store storeHash parsed from the paymentIdentifier can be injected.
 */
final class WebhookProcessor
{
    /**
     * @param callable(string $storeHash): BigCommerceClientInterface $bcClientFactory
     *   Factory that returns a configured BigCommerceClientInterface for a given storeHash.
     */
    public function __construct(
        private readonly mixed               $bcClientFactory,
        private readonly PaymentIdentifier   $identifier,
        private readonly PaymentStatusMapper $statusMapper,
        private readonly IdempotencyStoreInterface $idempotency,
        private readonly LoggerInterface           $logger,
        private readonly string              $webhookSecret,
    ) {}

    /**
     * Entry point called by public/webhook.php.
     *
     * @param string               $rawBody  Raw request body (before any decoding).
     * @param array<string,string> $headers  Request headers (keys may be mixed-case).
     * @throws \RuntimeException on signature failure or transport errors.
     * @throws \InvalidArgumentException on malformed payload.
     */
    public function process(string $rawBody, array $headers): void
    {
        // 1. Verify HMAC-SHA256 signature.
        $this->verifySignature($rawBody, $headers);

        // 2. Decode payload.
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Webhook payload must be a JSON object.');
        }

        $transactionId     = (string) ($payload['transactionId']     ?? $payload['rrr'] ?? '');
        $paymentIdentifier = (string) ($payload['paymentIdentifier'] ?? '');

        if ($transactionId === '' || $paymentIdentifier === '') {
            throw new \InvalidArgumentException(
                'Webhook payload missing transactionId or paymentIdentifier.'
            );
        }

        // 3. Map status.
        $canonicalStatus = $this->statusMapper->fromWebhookPayload($payload);

        // 4. Idempotency check — skip if this exact outcome was already applied.
        $idempotencyKey = "wh:{$transactionId}:{$canonicalStatus}";
        if ($this->idempotency->has($idempotencyKey)) {
            $this->logger->info('Webhook duplicate skipped', [
                'transactionId' => $transactionId,
                'status'        => $canonicalStatus,
            ]);
            return;
        }

        // 5. Parse the payment identifier to get storeHash + orderId.
        $parsed    = $this->identifier->parse($paymentIdentifier);
        $storeHash = $parsed['storeHash'];
        $orderId   = $parsed['orderId'];

        // Build a BigCommerceClientInterface scoped to the correct store.
        /** @var BigCommerceClientInterface $bcClient */
        $bcClient = ($this->bcClientFactory)($storeHash);

        // 6. Fetch the current BigCommerce order to enforce the regression guard.
        $order           = $bcClient->getOrder($orderId);
        $currentBcStatus = (string) ($order['payment_status'] ?? '');
        $resolvedStatus  = $this->statusMapper->resolve($currentBcStatus, $canonicalStatus);

        // 7. Apply the resolved status to the BigCommerce order.
        $this->applyStatusToOrder($bcClient, $orderId, $resolvedStatus, $transactionId);

        // 8. Mark idempotency record AFTER successful update.
        $this->idempotency->set($idempotencyKey, [
            'transactionId'   => $transactionId,
            'orderId'         => $orderId,
            'storeHash'       => $storeHash,
            'canonicalStatus' => $canonicalStatus,
            'resolvedStatus'  => $resolvedStatus,
            'processedAt'     => gmdate('c'),
        ]);

        $this->logger->info('Webhook processed', [
            'transactionId'   => $transactionId,
            'orderId'         => $orderId,
            'storeHash'       => $storeHash,
            'canonicalStatus' => $canonicalStatus,
            'resolvedStatus'  => $resolvedStatus,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Verify the HMAC-SHA256 signature on the raw webhook body.
     *
     * Remita sends the digest in the X-Remita-Signature header.
     * Headers are normalised to lowercase for case-insensitive lookup.
     *
     * @param array<string,string> $headers
     * @throws \RuntimeException on missing or invalid signature.
     */
    private function verifySignature(string $rawBody, array $headers): void
    {
        // Normalise header keys to lowercase for case-insensitive comparison.
        $normalised = array_change_key_case($headers, CASE_LOWER);
        $signature  = $normalised['x-remita-signature'] ?? '';

        if ($signature === '') {
            throw new \RuntimeException('Webhook rejected: missing X-Remita-Signature header.');
        }

        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);

        if (!hash_equals($expected, strtolower($signature))) {
            throw new \RuntimeException('Webhook rejected: signature mismatch.');
        }
    }

    /**
     * Map the canonical status to a BigCommerce status_id and update the order.
     *
     * BigCommerce status IDs used:
     *   10 = Awaiting Fulfillment  (payment captured / success)
     *   2  = Pending
     *   1  = Incomplete            (failed / unresolved)
     */
    private function applyStatusToOrder(
        BigCommerceClientInterface $bcClient,
        int               $orderId,
        string            $status,
        string            $transactionId,
    ): void {
        $statusId = match ($status) {
            'success' => 10,
            'pending' => 2,
            default   => 1,
        };

        $paymentStatus = match ($status) {
            'success' => 'captured',
            'pending' => 'pending',
            default   => 'failed',
        };

        $bcClient->updateOrder($orderId, [
            'status_id'      => $statusId,
            'payment_status' => $paymentStatus,
        ]);

        // Store the Remita transaction ID in order metafields on success.
        if ($status === 'success') {
            $bcClient->createOrderMetafield(
                $orderId,
                'remita_transaction_id',
                $transactionId
            );
        }
    }
}
