<?php
declare(strict_types=1);

/**
 * BigCommerce OAuth install callback.
 *
 * BigCommerce redirects here after the merchant clicks "Install" in the App Store.
 * This script exchanges the temporary code for a permanent access token and
 * persists the store credentials in a file-based store (no database required).
 *
 * File stored at: {data_dir}/stores/{storeHash}.json  (atomic write)
 *
 * Query parameters supplied by BigCommerce:
 *   code     string  Temporary OAuth authorisation code
 *   context  string  e.g. "stores/abc123"
 *   scope    string  Granted OAuth scopes
 */

$root = dirname(__DIR__);
require_once $root . '/src/Support/LoggerService.php';

$config = require $root . '/config.php';
$logger = new \Remita\BigCommerce\Support\LoggerService($config['log_dir']);

$code    = $_GET['code']    ?? '';
$context = $_GET['context'] ?? '';

if ($code === '' || $context === '') {
    http_response_code(400);
    echo 'Invalid installation request: missing code or context.';
    exit;
}

// Extract storeHash from context string like "stores/abc123".
$storeHash = ltrim((string) str_replace('stores/', '', $context), '/');
$storeHash = preg_replace('/[^a-zA-Z0-9_-]/', '', $storeHash);

if ($storeHash === '') {
    http_response_code(400);
    echo 'Invalid installation request: could not parse store hash from context.';
    exit;
}

try {
    // Exchange the temporary code for a permanent access token.
    $accessToken = exchangeOAuthCode($code, $context, $_GET['scope'] ?? '', $config);

    // Persist the store credentials to a JSON file (atomic write).
    persistStoreCredentials($storeHash, $accessToken, $config);

    $logger->info('Store installed', ['storeHash' => $storeHash]);

    echo '<h3>Installation Successful!</h3>';
    echo '<p>Your store has been connected. Please configure your Remita API keys in the app settings.</p>';
} catch (\Throwable $e) {
    $logger->error('Install failed', ['storeHash' => $storeHash, 'message' => $e->getMessage()]);
    http_response_code(500);
    echo 'Installation failed: ' . htmlspecialchars($e->getMessage());
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Exchange a temporary OAuth code for a permanent BigCommerce access token.
 *
 * @param  array<string,mixed> $config
 * @return string The permanent access token.
 * @throws \RuntimeException on cURL or HTTP failure.
 */
function exchangeOAuthCode(string $code, string $context, string $scope, array $config): string
{
    $tokenUrl = 'https://login.bigcommerce.com/oauth2/token';
    $payload  = http_build_query([
        'client_id'     => $config['bigcommerce_client_id'],
        'client_secret' => $config['bigcommerce_client_secret'],
        'code'          => $code,
        'scope'         => $scope,
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => $config['bigcommerce_redirect_uri'],
        'context'       => $context,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $tokenUrl,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw      = curl_exec($ch);
    $errno    = curl_errno($ch);
    $errMsg   = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new \RuntimeException("OAuth token exchange cURL error ({$errno}): {$errMsg}");
    }

    if ($httpCode !== 200) {
        throw new \RuntimeException("OAuth token exchange HTTP {$httpCode}: {$raw}");
    }

    $data = json_decode((string) $raw, true);
    if (!is_array($data) || empty($data['access_token'])) {
        throw new \RuntimeException("OAuth token exchange: missing access_token in response: {$raw}");
    }

    return (string) $data['access_token'];
}

/**
 * Persist store credentials to a JSON file using an atomic write.
 *
 * Path: {data_dir}/stores/{storeHash}.json
 *
 * @param  array<string,mixed> $config
 * @throws \RuntimeException on file system error.
 */
function persistStoreCredentials(string $storeHash, string $accessToken, array $config): void
{
    $storesDir = rtrim($config['data_dir'], '/') . '/stores';

    if (!is_dir($storesDir) && !mkdir($storesDir, 0755, true) && !is_dir($storesDir)) {
        throw new \RuntimeException("Cannot create stores directory: {$storesDir}");
    }

    $record = json_encode([
        'store_hash'               => $storeHash,
        'bigcommerce_access_token' => $accessToken,
        'installed_at'             => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $finalPath = $storesDir . '/' . $storeHash . '.json';
    $tmpPath   = $finalPath . '.tmp';

    if (file_put_contents($tmpPath, $record, LOCK_EX) === false) {
        throw new \RuntimeException("Cannot write store credentials to {$tmpPath}");
    }

    if (!rename($tmpPath, $finalPath)) {
        @unlink($tmpPath);
        throw new \RuntimeException("Cannot atomically rename {$tmpPath} to {$finalPath}");
    }
}
