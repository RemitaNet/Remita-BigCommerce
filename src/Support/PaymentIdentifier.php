<?php
declare(strict_types=1);

namespace Remita\BigCommerce\Support;

/**
 * Generates and parses the opaque payment identifier that travels through
 * the Remita checkout flow.
 *
 * Format: bc-{entityId}-{timestamp}-{random6}
 *
 * Where entityId = "{storeHash}_{orderId}"
 *
 * Example: bc-abc123_456-1719820000-f4e3d2
 *
 * The entityId encodes both storeHash and orderId so the callback can
 * reconstruct both without any database or file lookup.
 */
final class PaymentIdentifier
{
    private const PREFIX = 'bc';

    /**
     * Build a new identifier for the given store + order combination.
     *
     * @param string $storeHash BigCommerce store hash (alphanumeric slug).
     * @param int    $orderId   BigCommerce order ID.
     */
    public function generate(string $storeHash, int $orderId): string
    {
        $entityId  = $storeHash . '_' . $orderId;
        $timestamp = (string) time();
        $random    = bin2hex(random_bytes(3)); // 6 hex chars

        return implode('-', [self::PREFIX, $entityId, $timestamp, $random]);
    }

    /**
     * Validate and parse a payment identifier string.
     *
     * Returns an associative array with keys: prefix, storeHash, orderId,
     * timestamp, random — or throws \InvalidArgumentException on bad input.
     *
     * @return array{prefix:string, storeHash:string, orderId:int, timestamp:int, random:string}
     * @throws \InvalidArgumentException
     */
    public function parse(string $identifier): array
    {
        // Split on '-' limiting to 4 parts: prefix, entityId, timestamp, random
        $parts = explode('-', $identifier, 4);

        if (count($parts) !== 4) {
            throw new \InvalidArgumentException(
                "Invalid payment identifier (expected 4 dash-separated segments): {$identifier}"
            );
        }

        [$prefix, $entityId, $timestamp, $random] = $parts;

        if ($prefix !== self::PREFIX) {
            throw new \InvalidArgumentException(
                "Invalid payment identifier prefix '{$prefix}', expected '" . self::PREFIX . "'"
            );
        }

        // entityId = {storeHash}_{orderId}
        $underscorePos = strrpos($entityId, '_');
        if ($underscorePos === false) {
            throw new \InvalidArgumentException(
                "Invalid entityId format in payment identifier: {$entityId}"
            );
        }

        $storeHash = substr($entityId, 0, $underscorePos);
        $orderId   = (int) substr($entityId, $underscorePos + 1);

        if ($storeHash === '' || $orderId <= 0) {
            throw new \InvalidArgumentException(
                "Could not extract storeHash/orderId from payment identifier: {$identifier}"
            );
        }

        return [
            'prefix'    => $prefix,
            'storeHash' => $storeHash,
            'orderId'   => $orderId,
            'timestamp' => (int) $timestamp,
            'random'    => $random,
        ];
    }

    /**
     * Quick validity check without throwing.
     */
    public function isValid(string $identifier): bool
    {
        try {
            $this->parse($identifier);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
