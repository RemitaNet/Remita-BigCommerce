<?php
declare(strict_types=1);

use Remita\BigCommerce\Support\AmountNormalizer;
use Remita\BigCommerce\Support\OrderMapper;
use Remita\BigCommerce\Support\PaymentIdentifier;

class OrderMapperTest extends TestCase
{
    private OrderMapper $mapper;

    public function __construct()
    {
        $this->mapper = new OrderMapper(new AmountNormalizer());
    }

    public function run(): void
    {
        $this->testNormalizeFullOrder();
        $this->testNormalizeCurrencyUppercased();
        $this->testNormalizeAmountKobo();
        $this->testNormalizeMissingFieldThrows();
        $this->testNormalizeMissingBillingThrows();
        $this->testToRemitaPayload();
        $this->testToRemitaPayloadAmountIsKoboInt();
    }

    /** @return array<string,mixed> */
    private function validRawOrder(): array
    {
        return [
            'id'            => 42,
            'total_inc_tax' => '1500.00',
            'currency_code' => 'ngn',
            'billing_address' => [
                'email'      => 'buyer@example.com',
                'first_name' => 'John',
                'last_name'  => 'Doe',
                'phone'      => '+2348012345678',
            ],
        ];
    }

    private function testNormalizeFullOrder(): void
    {
        $result = $this->mapper->normalize($this->validRawOrder(), 'store1');

        $this->assertSame(42,                    $result['id'],        'id');
        $this->assertSame('store1',              $result['storeHash'], 'storeHash');
        $this->assertSame('buyer@example.com',   $result['email'],     'email');
        $this->assertSame('John',                $result['firstName'], 'firstName');
        $this->assertSame('Doe',                 $result['lastName'],  'lastName');
        $this->assertSame('+2348012345678',      $result['phone'],     'phone');
    }

    private function testNormalizeCurrencyUppercased(): void
    {
        $result = $this->mapper->normalize($this->validRawOrder(), 's');
        $this->assertSame('NGN', $result['currency'], 'currency uppercased');
    }

    private function testNormalizeAmountKobo(): void
    {
        $result = $this->mapper->normalize($this->validRawOrder(), 's');
        $this->assertSame(150000, $result['amountKobo'], '1500.00 NGN = 150000 kobo');
    }

    private function testNormalizeMissingFieldThrows(): void
    {
        $caught = false;
        $order  = $this->validRawOrder();
        unset($order['total_inc_tax']);
        try {
            $this->mapper->normalize($order, 's');
        } catch (\RuntimeException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'missing total_inc_tax throws RuntimeException');
    }

    private function testNormalizeMissingBillingThrows(): void
    {
        $caught = false;
        $order  = $this->validRawOrder();
        $order['billing_address'] = 'not-an-array';
        try {
            $this->mapper->normalize($order, 's');
        } catch (\RuntimeException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'non-array billing_address throws RuntimeException');
    }

    private function testToRemitaPayload(): void
    {
        $order = $this->mapper->normalize($this->validRawOrder(), 'store1');
        $payload = $this->mapper->toRemitaPayload($order, 'bc-store1_42-123-abc', 'https://example.com/callback');

        $this->assertSame('John',                 $payload['firstName'],        'payload firstName');
        $this->assertSame('Doe',                  $payload['lastName'],         'payload lastName');
        $this->assertSame('buyer@example.com',    $payload['email'],            'payload email');
        $this->assertSame('+2348012345678',        $payload['phoneNumber'],      'payload phoneNumber');
        $this->assertSame('bc-store1_42-123-abc', $payload['paymentIdentifier'],'payload paymentIdentifier');
        $this->assertSame('NGN',                  $payload['currency'],         'payload currency');
        $this->assertSame('BigCommerce Order #42', $payload['narration'],       'payload narration');
        $this->assertSame('https://example.com/callback', $payload['returnUrl'], 'payload returnUrl');
    }

    private function testToRemitaPayloadAmountIsKoboInt(): void
    {
        $order   = $this->mapper->normalize($this->validRawOrder(), 'store1');
        $payload = $this->mapper->toRemitaPayload($order, 'id', 'https://cb');

        $this->assertTrue(is_int($payload['amount']), 'amount is an integer (kobo)');
        $this->assertSame(150000, $payload['amount'], 'amount = 150000 kobo');
    }
}
