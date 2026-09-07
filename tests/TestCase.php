<?php
declare(strict_types=1);

abstract class TestCase
{
    private int $assertions = 0;
    private int $failures   = 0;

    abstract public function run(): void;

    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->failures++;
            echo "FAIL: " . ($msg ?: 'assertSame')
               . " expected=" . var_export($expected, true)
               . " got="      . var_export($actual,   true) . "\n";
        }
    }

    protected function assertNull(mixed $v, string $m = ''): void
    {
        $this->assertSame(null, $v, $m ?: 'assertNull');
    }

    protected function assertTrue(mixed $v, string $m = ''): void
    {
        $this->assertSame(true, $v, $m ?: 'assertTrue');
    }

    protected function assertFalse(mixed $v, string $m = ''): void
    {
        $this->assertSame(false, $v, $m ?: 'assertFalse');
    }

    protected function assertNotNull(mixed $v, string $m = ''): void
    {
        $this->assertions++;
        if ($v === null) {
            $this->failures++;
            echo "FAIL: " . ($m ?: 'assertNotNull') . "\n";
        }
    }

    public function report(): void
    {
        $s = $this->failures === 0 ? 'PASS' : 'FAIL';
        echo sprintf(
            "[%s] %s — %d assertions, %d failures\n",
            $s,
            static::class,
            $this->assertions,
            $this->failures
        );
    }
}
