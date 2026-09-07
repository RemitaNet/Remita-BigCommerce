<?php
declare(strict_types=1);

use Remita\BigCommerce\Support\AmountNormalizer;

class AmountNormalizerTest extends TestCase
{
    private AmountNormalizer $norm;

    public function __construct()
    {
        $this->norm = new AmountNormalizer();
    }

    public function run(): void
    {
        $this->testWholeNairaToKobo();
        $this->testDecimalNairaToKobo();
        $this->testStringInputToKobo();
        $this->testZeroToKobo();
        $this->testFloatingPointDrift();
        $this->testNegativeThrows();
        $this->testNonNumericThrows();
        $this->testKoboToNaira();
        $this->testKoboToNairaZero();
        $this->testKoboToNairaLarge();
    }

    private function testWholeNairaToKobo(): void
    {
        $this->assertSame(150000, $this->norm->toKobo(1500), '1500 NGN = 150000 kobo');
        $this->assertSame(100,    $this->norm->toKobo(1),    '1 NGN = 100 kobo');
    }

    private function testDecimalNairaToKobo(): void
    {
        $this->assertSame(150050, $this->norm->toKobo(1500.50), '1500.50 NGN = 150050 kobo');
        $this->assertSame(50,     $this->norm->toKobo(0.50),    '0.50 NGN = 50 kobo');
    }

    private function testStringInputToKobo(): void
    {
        $this->assertSame(150000, $this->norm->toKobo('1500.00'), '"1500.00" as string');
        $this->assertSame(1,      $this->norm->toKobo('0.01'),    '"0.01" as string = 1 kobo');
    }

    private function testZeroToKobo(): void
    {
        $this->assertSame(0, $this->norm->toKobo(0), '0 NGN = 0 kobo');
        $this->assertSame(0, $this->norm->toKobo('0'), '"0" = 0 kobo');
    }

    private function testFloatingPointDrift(): void
    {
        // 5.99 in float can drift; rounding should correct it.
        $this->assertSame(599, $this->norm->toKobo(5.99), '5.99 NGN = 599 kobo (no drift)');
        $this->assertSame(10,  $this->norm->toKobo(0.10), '0.10 NGN = 10 kobo (no drift)');
    }

    private function testNegativeThrows(): void
    {
        $caught = false;
        try {
            $this->norm->toKobo(-1.0);
        } catch (\InvalidArgumentException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'negative amount throws InvalidArgumentException');
    }

    private function testNonNumericThrows(): void
    {
        $caught = false;
        try {
            $this->norm->toKobo('abc');
        } catch (\InvalidArgumentException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'non-numeric string throws InvalidArgumentException');
    }

    private function testKoboToNaira(): void
    {
        $this->assertSame('1500.00', $this->norm->toNaira(150000), '150000 kobo = "1500.00"');
        $this->assertSame('0.50',    $this->norm->toNaira(50),     '50 kobo = "0.50"');
    }

    private function testKoboToNairaZero(): void
    {
        $this->assertSame('0.00', $this->norm->toNaira(0), '0 kobo = "0.00"');
    }

    private function testKoboToNairaLarge(): void
    {
        $this->assertSame('1000000.00', $this->norm->toNaira(100000000), '100_000_000 kobo = "1000000.00"');
    }
}
