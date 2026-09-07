<?php
declare(strict_types=1);

namespace Remita\BigCommerce\Support;

/**
 * Contract for a key-value idempotency store.
 */
interface IdempotencyStoreInterface
{
    /**
     * Check whether a key has already been processed.
     */
    public function has(string $key): bool;

    /**
     * Store a JSON-serialisable value under the given key.
     *
     * @param mixed $value
     */
    public function set(string $key, mixed $value): void;

    /**
     * Retrieve the stored value for a key, or null if it does not exist.
     *
     * @return mixed|null
     */
    public function get(string $key): mixed;
}
