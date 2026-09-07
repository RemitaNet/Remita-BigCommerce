<?php
declare(strict_types=1);

namespace Remita\BigCommerce\Support;

/**
 * Minimal PSR-3-compatible logger contract.
 */
interface LoggerInterface
{
    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void;

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void;

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void;
}
