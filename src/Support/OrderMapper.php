<?php
declare(strict_types=1);

namespace Remita\BigCommerce\Support;

/**
 * Transforms the raw BigCommerce order array into a normalised shape used
 * internally by the adapter, and builds the Remita charge payload.
 */
final class OrderMapper
{
    public function __construct(
        private readonly AmountNormalizer $normalizer,
    ) {}

    /**
     * Normalise a raw BigCommerce order array.
     *
     * @param  array<string,mixed> $rawOrder   Response from GET /v2/orders/{id}
     * @param  string              $storeHash  BigCommerce store hash
     * @return array{id:int, storeHash:string, amountKobo:int, currency:string,
     *               email:string, firstName:string, lastName:string, phone:string}
     * @throws \RuntimeException on missing required fields.
     */
    public function normalize(array $rawOrder, string $storeHash): array
    {
        $required = ['id', 'total_inc_tax', 'currency_code', 'billing_address'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $rawOrder)) {
                throw new \RuntimeException(
                    "BigCommerce order response missing required field: {$field}"
                );
            }
        }

        $billing = $rawOrder['billing_address'];
        if (!is_array($billing)) {
            throw new \RuntimeException(
                'BigCommerce order billing_address is not an array.'
            );
        }

        return [
            'id'         => (int) $rawOrder['id'],
            'storeHash'  => $storeHash,
            'amountKobo' => $this->normalizer->toKobo($rawOrder['total_inc_tax']),
            'currency'   => strtoupper((string) $rawOrder['currency_code']),
            'email'      => (string) ($billing['email']      ?? ''),
            'firstName'  => (string) ($billing['first_name'] ?? ''),
            'lastName'   => (string) ($billing['last_name']  ?? ''),
            'phone'      => (string) ($billing['phone']      ?? ''),
        ];
    }

    /**
     * Build the Remita /payment/charge request payload from a normalised order.
     *
     * @param array<string,mixed> $order      Return value of normalize().
     * @param string              $identifier PaymentIdentifier string.
     * @param string              $returnUrl  Full callback URL including identifier param.
     * @return array<string,mixed>
     */
    public function toRemitaPayload(array $order, string $identifier, string $returnUrl): array
    {
        return [
            'firstName'         => $order['firstName'],
            'lastName'          => $order['lastName'],
            'email'             => $order['email'],
            'phoneNumber'       => $order['phone'],
            'paymentIdentifier' => $identifier,
            'currency'          => $order['currency'],
            'narration'         => 'BigCommerce Order #' . $order['id'],
            // Remita expects amount in kobo as an integer
            'amount'            => $order['amountKobo'],
            'returnUrl'         => $returnUrl,
        ];
    }
}
