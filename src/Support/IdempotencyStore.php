<?php
declare(strict_types=1);

namespace Remita\BigCommerce\Support;

/**
 * File-based idempotency store.
 *
 * Each record is a JSON file at:
 *   {dataDir}/idempotency/{sha256(key)}.json
 *
 * Records contain the stored value and an ISO-8601 created_at timestamp.
 * Writes are atomic: data is written to a .tmp file then rename()d into place,
 * so a crash mid-write never leaves a partial record.
 */
final class IdempotencyStore implements IdempotencyStoreInterface
{
    private string $dir;

    /**
     * @param string $dataDir Absolute path to the writable data directory.
     *                        The idempotency sub-directory is created automatically.
     * @throws \RuntimeException if the directory cannot be created.
     */
    public function __construct(string $dataDir)
    {
        $this->dir = rtrim($dataDir, '/') . '/idempotency';

        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(
                "IdempotencyStore: cannot create directory {$this->dir}"
            );
        }
    }

    /**
     * Check whether a key has already been processed.
     */
    public function has(string $key): bool
    {
        return file_exists($this->path($key));
    }

    /**
     * Store an arbitrary JSON-serialisable value under the given key.
     *
     * Uses an atomic write: writes to .tmp then rename()s into place.
     *
     * @param mixed $value Any JSON-serialisable value.
     * @throws \RuntimeException if the write or rename fails.
     */
    public function set(string $key, mixed $value): void
    {
        $record = json_encode([
            'key'        => $key,
            'value'      => $value,
            'created_at' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $finalPath = $this->path($key);
        $tmpPath   = $finalPath . '.tmp';

        if (file_put_contents($tmpPath, $record, LOCK_EX) === false) {
            throw new \RuntimeException(
                "IdempotencyStore: failed to write temporary file {$tmpPath}"
            );
        }

        if (!rename($tmpPath, $finalPath)) {
            // Best-effort cleanup before throwing.
            @unlink($tmpPath);
            throw new \RuntimeException(
                "IdempotencyStore: failed to atomically rename {$tmpPath} to {$finalPath}"
            );
        }
    }

    /**
     * Retrieve the stored value for a key, or null if it does not exist.
     *
     * @return mixed|null
     * @throws \RuntimeException if the file exists but cannot be read or decoded.
     */
    public function get(string $key): mixed
    {
        $path = $this->path($key);

        if (!file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(
                "IdempotencyStore: cannot read {$path}"
            );
        }

        $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return $record['value'] ?? null;
    }

    /**
     * Return the full filesystem path for a given key.
     */
    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }
}
