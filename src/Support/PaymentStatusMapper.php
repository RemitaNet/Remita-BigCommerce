<?php
declare(strict_types=1);

namespace Remita\BigCommerce\Support;

/**
 * Maps Remita payment response codes and states to a canonical status string.
 *
 * Canonical statuses: 'success' | 'pending' | 'failed'
 *
 * Rules (evaluated in order):
 *  - status '00' OR paymentState 'APPROVED'                                 -> success
 *  - status in {01,02,03,04,09,45} OR paymentState in {PENDING,PROCESSING}  -> pending
 *  - webhook body status in {success,approved,completed}                     -> success
 *  - webhook body status in {pending,processing,redirect}                    -> pending
 *  - anything else                                                           -> failed
 *
 * Regression guard: once a status reaches 'success' it must never be downgraded.
 */
final class PaymentStatusMapper
{
    /** Remita API response code that signals full capture. */
    private const SUCCESS_CODE = '00';

    /** @var string[] Remita response codes that represent an in-flight payment. */
    private const PENDING_CODES = ['01', '02', '03', '04', '09', '45'];

    /** @var string[] paymentState values that signal success. */
    private const SUCCESS_STATES = ['APPROVED'];

    /** @var string[] paymentState values that signal pending. */
    private const PENDING_STATES = ['PENDING', 'PROCESSING'];

    /** @var string[] Webhook body status strings that map to success. */
    private const WEBHOOK_SUCCESS = ['success', 'approved', 'completed'];

    /** @var string[] Webhook body status strings that map to pending. */
    private const WEBHOOK_PENDING = ['pending', 'processing', 'redirect'];

    /**
     * Map a Remita payment-query API response to a canonical status.
     *
     * @param array<string,mixed> $response Decoded JSON from Remita /payment/query/{id}
     */
    public function fromApiResponse(array $response): string
    {
        $code  = isset($response['status']) ? (string) $response['status'] : '';
        $state = isset($response['paymentState']) ? strtoupper((string) $response['paymentState']) : '';

        if ($code === self::SUCCESS_CODE || in_array($state, self::SUCCESS_STATES, true)) {
            return 'success';
        }

        if (in_array($code, self::PENDING_CODES, true) || in_array($state, self::PENDING_STATES, true)) {
            return 'pending';
        }

        return 'failed';
    }

    /**
     * Map a Remita webhook payload to a canonical status.
     *
     * @param array<string,mixed> $payload Decoded webhook JSON body
     */
    public function fromWebhookPayload(array $payload): string
    {
        $raw = isset($payload['status']) ? strtolower((string) $payload['status']) : '';

        if (in_array($raw, self::WEBHOOK_SUCCESS, true)) {
            return 'success';
        }

        if (in_array($raw, self::WEBHOOK_PENDING, true)) {
            return 'pending';
        }

        return 'failed';
    }

    /**
     * Enforce the regression rule: a terminal success status must never be downgraded.
     *
     * Also guards: paid, complete, completed, captured are treated as terminal.
     *
     * @param string $current  The status already stored (may be empty string if none).
     * @param string $incoming The newly computed canonical status.
     */
    public function resolve(string $current, string $incoming): string
    {
        $terminal = ['success', 'paid', 'complete', 'completed', 'captured'];

        if (in_array(strtolower($current), $terminal, true)) {
            return 'success';
        }

        return $incoming;
    }
}
