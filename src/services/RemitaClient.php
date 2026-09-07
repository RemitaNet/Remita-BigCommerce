<?php

class RemitaClient
{
private string $baseUrl;
private string $secretKey;

public function __construct($baseUrl, $secretKey)
{
$this->baseUrl = rtrim($baseUrl, '/');
$this->secretKey = $secretKey;
}

public function charge(array $payload): array
{
$url = $this->baseUrl . '/api/v1/payment/charge';

$ch = curl_init();

curl_setopt_array($ch, [
CURLOPT_URL => $url,
CURLOPT_POST => true,
CURLOPT_RETURNTRANSFER => true,
CURLOPT_HTTPHEADER => [
'Content-Type: application/json',
'secretKey: ' . $this->secretKey
],
CURLOPT_POSTFIELDS => json_encode($payload),
CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($error) {
throw new Exception("Remita cURL error: " . $error);
}

$decoded = json_decode($response, true);

if ($httpCode !== 200) {
throw new Exception("Remita API failed: " . $response);
}

return $decoded;
}
    /**
     * Verify payment status using the payment identifier
     */
    public function verifyPayment(string $paymentIdentifier): array
    {
        $url = $this->baseUrl . '/api/v1/payment/query/' . urlencode($paymentIdentifier);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'secretKey: ' . $this->secretKey
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($error) {
            throw new Exception("Remita Verification cURL error: " . $error);
        }

        $decoded = json_decode($response, true);

        if ($httpCode !== 200) {
            throw new Exception("Remita Verification failed with status code {$httpCode}: " . $response);
        }

        return $decoded;
    }
}