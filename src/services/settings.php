<?php
// public/settings.php

require_once __DIR__ . '/../vendor/autoload.php'; // Load Composer dependencies
require_once __DIR__ . '/../src/services/Database.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$message = '';
$storeHash = null;
$isAdminVerified = false;

// 1. VERIFY ADMIN IDENTIFICATION VIA BIGCOMMERCE JWT
// When loading an app inside the dashboard iframe, BigCommerce passes a 'signed_payload_jwt' query string parameter
$jwtPayload = $_GET['signed_payload_jwt'] ?? $_POST['signed_payload_jwt'] ?? null;

if ($jwtPayload) {
    try {
        // Your Client Secret from the BigCommerce Developer Portal (never expose this to the frontend)
        $clientSecret = getenv('BIGCOMMERCE_CLIENT_SECRET');

        // Decode and cryptographically verify that this token came directly from BigCommerce
        $decoded = JWT::decode($jwtPayload, new Key($clientSecret, 'HS256'));

        // Ensure the payload structure matches an active store installation context
        if (isset($decoded->store_hash)) {
            $storeHash = $decoded->store_hash;
            $isAdminVerified = true;
        }
    } catch (Exception $e) {
        // Token was tampered with, expired, or signed with the wrong secret key
        http_response_code(403);
        die("Security Access Denied: Invalid authentication signature.");
    }
}

// Block entry completely if the user did not arrive via an authenticated BigCommerce Dashboard panel session
if (!$isAdminVerified || !$storeHash) {
    http_response_code(403);
    die("Security Access Denied: This setting configuration layout can only be accessed by verified store administrators within the BigCommerce Control Panel.");
}

// 2. HANDLE FORM SUBMISSION (SAVING CONFIGURATIONS)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $baseUrl   = rtrim($_POST['remita_base_url'], '/');
    $secretKey = $_POST['remita_secret_key'];

    try {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE merchants 
            SET remita_base_url = :base_url, remita_secret_key = :secret_key 
            WHERE store_hash = :store_hash
        ");
        $stmt->execute([
            'base_url'   => $baseUrl,
            'secret_key' => $secretKey,
            'store_hash' => $storeHash
        ]);
        $message = "<div class='alert success'>Remita settings saved successfully!</div>";
    } catch (Exception $e) {
        $message = "<div class='alert error'>Error saving configuration fields: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// 3. FETCH DATA FOR THE VERIFIED STORE
$currentSettings = Database::getMerchantCredentials($storeHash);
$existingBaseUrl   = $currentSettings['remita_base_url'] ?? 'https://remitademo.net';
$existingSecretKey = $currentSettings['remita_secret_key'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Remita Secure Merchant Settings</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f6f7f9; color: #333; padding: 20px; }
        .container { max-width: 500px; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin: 0 auto; }
        h2 { margin-top: 0; color: #111; border-bottom: 2px solid #eaeaea; padding-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 14px; }
        button { background: #0070e0; color: white; border: none; padding: 12px 20px; font-size: 14px; border-radius: 4px; cursor: pointer; font-weight: bold; width: 100%; }
        button:hover { background: #005cb8; }
        .alert { padding: 12px; margin-bottom: 20px; border-radius: 4px; font-size: 14px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .hint { font-size: 12px; color: #666; margin-top: 4px; }
    </style>
</head>
<body>

<div class="container">
    <h2>Remita Administration</h2>

    <?php echo $message; ?>

    <form method="POST" action="?signed_payload_jwt=<?php echo urlencode($jwtPayload); ?>">
        <div class="form-group">
            <label Lothar for="remita_base_url">Remita API Base URL</label>
            <input type="text" id="remita_base_url" name="remita_base_url" value="<?php echo htmlspecialchars($existingBaseUrl); ?>" required>
            <div class="hint">Use <code>https://remitademo.net</code> for sandbox testing environments.</div>
        </div>

        <div class="form-group">
            <label for="remita_secret_key">Remita Secret Key</label>
            <input type="password" id="remita_secret_key" name="remita_secret_key" value="<?php echo htmlspecialchars($existingSecretKey); ?>" placeholder="Enter your Remita Gateway Secret Key" required>
        </div>

        <button type="submit">Update Gateway Settings</button>
    </form>
</div>

</body>
</html>