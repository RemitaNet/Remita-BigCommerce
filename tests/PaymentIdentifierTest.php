<?php
declare(strict_types=1);

use Remita\BigCommerce\Support\PaymentIdentifier;

class PaymentIdentifierTest extends TestCase
{
    private PaymentIdentifier $pi;

    public function __construct()
    {
        $this->pi = new PaymentIdentifier();
    }

    public function run(): void
    {
        $this->testGenerateFormat();
        $this->testGeneratePrefix();
        $this->testGenerateUniqueness();
        $this->testParseRoundTrip();
        $this->testParseRejectsWrongPrefix();
        $this->testParseRejectsMissingSegments();
        $this->testParseRejectsMissingUnderscore();
        $this->testParseRejectsZeroOrderId();
        $this->testIsValid();
        $this->testIsInvalid();
    }

    private function testGenerateFormat(): void
    {
        $id = $this->pi->generate('abc123', 456);
        // Must start with 'bc-'
        $this->assertTrue(str_starts_with($id, 'bc-'), 'generated id starts with bc-');
        // Must have exactly 4 dash-separated segments
        $parts = explode('-', $id, 4);
        $this->assertSame(4, count($parts), 'generated id has 4 dash-separated parts');
    }

    private function testGeneratePrefix(): void
    {
        $id    = $this->pi->generate('store1', 1);
        $parts = explode('-', $id, 4);
        $this->assertSame('bc', $parts[0], 'prefix is bc');
    }

    private function testGenerateUniqueness(): void
    {
        $a = $this->pi->generate('store1', 1);
        $b = $this->pi->generate('store1', 1);
        // Different random suffixes should make them distinct (with overwhelmingly high probability)
        $this->assertFalse($a === $b, 'consecutive generates produce unique identifiers');
    }

    private function testParseRoundTrip(): void
    {
        $id     = $this->pi->generate('mystore', 789);
        $parsed = $this->pi->parse($id);

        $this->assertSame('bc',      $parsed['prefix'],    'prefix round-trip');
        $this->assertSame('mystore', $parsed['storeHash'], 'storeHash round-trip');
        $this->assertSame(789,       $parsed['orderId'],   'orderId round-trip');
        $this->assertTrue($parsed['timestamp'] > 0,        'timestamp > 0');
        $this->assertSame(6, strlen($parsed['random']),    'random is 6 hex chars');
    }

    private function testParseRejectsWrongPrefix(): void
    {
        $caught = false;
        try {
            $this->pi->parse('wc-store_1-1719820000-abc123');
        } catch (\InvalidArgumentException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'wrong prefix throws InvalidArgumentException');
    }

    private function testParseRejectsMissingSegments(): void
    {
        $caught = false;
        try {
            $this->pi->parse('bc-store_1-1719820000'); // only 3 segments
        } catch (\InvalidArgumentException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'too few segments throws InvalidArgumentException');
    }

    private function testParseRejectsMissingUnderscore(): void
    {
        $caught = false;
        try {
            $this->pi->parse('bc-NOUNDERSCORE-1719820000-abc123');
        } catch (\InvalidArgumentException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'missing underscore in entityId throws InvalidArgumentException');
    }

    private function testParseRejectsZeroOrderId(): void
    {
        $caught = false;
        try {
            $this->pi->parse('bc-mystore_0-1719820000-abc123');
        } catch (\InvalidArgumentException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'orderId=0 throws InvalidArgumentException');
    }

    private function testIsValid(): void
    {
        $id = $this->pi->generate('s1', 1);
        $this->assertTrue($this->pi->isValid($id), 'generated id is valid');
    }

    private function testIsInvalid(): void
    {
        $this->assertFalse($this->pi->isValid('garbage'), 'garbage string is invalid');
        $this->assertFalse($this->pi->isValid(''), 'empty string is invalid');
    }
}
