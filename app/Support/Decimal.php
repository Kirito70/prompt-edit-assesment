<?php

namespace App\Support;

/**
 * Money from the database as an exact 12-decimal string, matching the
 * DECIMAL(20,12) columns.
 */
final class Decimal
{
    public const SCALE = 12;

    public const ZERO = '0.000000000000';

    /**
     * Postgres returns NUMERIC as a string (kept exact); SQLite returns a float
     * (callers round it in SQL first). Anything that is not a finite number
     * becomes zero.
     *
     * @return numeric-string
     */
    public static function fromDatabase(mixed $value): string
    {
        if (is_float($value) && is_finite($value)) {
            $value = number_format($value, self::SCALE, '.', '');
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && is_numeric($value)
            ? bcadd($value, '0', self::SCALE)
            : self::ZERO;
    }
}
