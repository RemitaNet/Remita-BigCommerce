<?php
declare(strict_types=1);

namespace Remita\BigCommerce\Support;

/**
 * Converts monetary amounts between their display representation (NGN, two
 * decimal places) and the integer kobo representation required by Remita.
 *
 * Remita expects amounts in kobo (the smallest NGN unit: 1 NGN = 100 kobo).
 */
final class AmountNormalizer
{
    /**
     * Convert a NGN decimal amount to whole kobo.
     *
     * @param float|int|string $naira  e.g. 1500.00 or "1500"
     * @return int                     e.g. 150000
     * @throws \InvalidArgumentException if the value is negative or non-numeric.
     */
    public function toKobo(float|int|string $naira): int
    {
        if (!is_numeric($naira)) {
            throw new \InvalidArgumentException(
                "Amount must be numeric, got: " . var_export($naira, true)
            );
        }

        $value = (float) $naira;

        if ($value < 0) {
            throw new \InvalidArgumentException(
                "Amount must be non-negative, got: {$naira}"
            );
        }

        // Round to avoid floating-point drift (e.g. 0.1 + 0.2 != 0.3 issues).
        return (int) round($value * 100);
    }

    /**
     * Convert whole kobo back to a NGN decimal string (2 dp).
     *
     * @param int $kobo  e.g. 150000
     * @return string    e.g. "1500.00"
     */
    public function toNaira(int $kobo): string
    {
        return number_format($kobo / 100, 2, '.', '');
    }
}
