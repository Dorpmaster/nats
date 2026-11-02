<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use InvalidArgumentException;

/** @internal Exact unsigned decimal arithmetic; no floating point conversions. */
final class Decimal
{
    public const string UINT64_MAX = '18446744073709551615';
    public const string INT64_MAX  = '9223372036854775807';

    public static function parse(string $value, string $maximum, bool $positive = false): string
    {
        if (preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new InvalidArgumentException('Expected unsigned decimal digits');
        }
        $digits = ltrim($value, '0');
        $digits = $digits === '' ? '0' : $digits;
        if (($positive && $digits === '0') || self::compare($digits, $maximum) > 0) {
            throw new InvalidArgumentException('Decimal value outside supported range');
        }
        return $digits;
    }

    public static function compare(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    public static function toInt(string|null $value): int|null
    {
        return $value !== null && self::compare($value, (string) PHP_INT_MAX) <= 0 ? (int) $value : null;
    }

    /** Add one without integer/float coercion. */
    public static function increment(string $value): string
    {
        $value = self::parse($value, self::UINT64_MAX);
        if ($value === self::UINT64_MAX) {
            throw new InvalidArgumentException('UInt64 sequence overflow');
        }
        for ($index = strlen($value) - 1; $index >= 0; $index--) {
            if ($value[$index] !== '9') {
                $value[$index] = (string) ((int) $value[$index] + 1);
                return $value;
            }
            $value[$index] = '0';
        }
        return '1' . $value;
    }

    /** Subtract one from a positive canonical decimal. */
    public static function decrement(string $value): string
    {
        for ($index = strlen($value) - 1; $index >= 0; $index--) {
            if ($value[$index] !== '0') {
                $value[$index] = (string) ((int) $value[$index] - 1);
                break;
            }
            $value[$index] = '9';
        }
        return ltrim($value, '0') ?: '0';
    }
}
