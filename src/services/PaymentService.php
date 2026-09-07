<?php


class PaymentService
{
private RemitaClient $remita;

public function __construct(RemitaClient $remita)
{
$this->remita = $remita;
}

public function initiatePayment(array $order, string $callbackUrl): string
{
    $paymentIdentifier = 'BC-' . $order['id'];

$payload = [
"firstName" => $order['billing_address']['first_name'],
"lastName" => $order['billing_address']['last_name'],
"email" => $order['billing_address']['email'],
"phoneNumber" => $order['billing_address']['phone'],
"paymentIdentifier" => $paymentIdentifier,
"currency" => $order['currency'],
"narration" => "BigCommerce Order #" . $order['id'],
"amount" => $order['amount'],
"returnUrl" => $callbackUrl . "?paymentIdentifier=" . $paymentIdentifier
];

$response = $this->remita->charge($payload);

if (!isset($response['checkoutUrl'])) {
throw new Exception("Invalid Remita response");
}

return $response['checkoutUrl'];
}
}
