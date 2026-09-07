<?php
declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * Stubs all external/global functions so tests run with:
 *   php tests/run.php
 *
 * No web server, no database, no live API keys required.
 */

$srcRoot = dirname(__DIR__) . '/src';

// Load TestCase first.
require_once __DIR__ . '/TestCase.php';

// Load production classes (PSR-4 manual load — interfaces first).
require_once $srcRoot . '/Support/IdempotencyStoreInterface.php';
require_once $srcRoot . '/Support/LoggerInterface.php';
require_once $srcRoot . '/Support/AmountNormalizer.php';
require_once $srcRoot . '/Support/PaymentIdentifier.php';
require_once $srcRoot . '/Support/PaymentStatusMapper.php';
require_once $srcRoot . '/Support/OrderMapper.php';
require_once $srcRoot . '/Support/IdempotencyStore.php';
require_once $srcRoot . '/Support/LoggerService.php';
require_once $srcRoot . '/BigCommerceClientInterface.php';
require_once $srcRoot . '/BigCommerceClient.php';
require_once $srcRoot . '/BigCommerceAdapter.php';
require_once $srcRoot . '/Webhook/WebhookProcessor.php';

// ---------------------------------------------------------------------------
// cURL stubs — prevent any real HTTP calls during testing.
// Tests that need specific responses should override these via a test double
// or by using dependency injection (e.g. mock BigCommerceClient).
// ---------------------------------------------------------------------------

if (!function_exists('curl_init')) {
    function curl_init(string $url = ''): mixed { return null; }
}

if (!function_exists('curl_setopt_array')) {
    function curl_setopt_array(mixed $ch, array $opts): bool { return true; }
}

if (!function_exists('curl_setopt')) {
    function curl_setopt(mixed $ch, int $opt, mixed $val): bool { return true; }
}

if (!function_exists('curl_exec')) {
    function curl_exec(mixed $ch): string|bool { return '{}'; }
}

if (!function_exists('curl_errno')) {
    function curl_errno(mixed $ch): int { return 0; }
}

if (!function_exists('curl_error')) {
    function curl_error(mixed $ch): string { return ''; }
}

if (!function_exists('curl_getinfo')) {
    function curl_getinfo(mixed $ch, int $opt): mixed { return 200; }
}

if (!function_exists('curl_close')) {
    function curl_close(mixed $ch): void {}
}
