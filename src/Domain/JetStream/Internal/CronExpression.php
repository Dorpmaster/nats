<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use InvalidArgumentException;

/** @internal NATS six-field cron grammar; does not calculate local firing times. */
final class CronExpression
{
    public static function validate(string $expression): void
    {
        if (preg_match('/\A[^\s\p{Cc}]+(?: +[^\s\p{Cc}]+){5}\z/u', $expression) !== 1) {
            throw new InvalidArgumentException('Invalid cron expression: expected six fields including seconds');
        }
        $limits = [[0, 59], [0, 59], [0, 23], [1, 31], [1, 12], [0, 6]];
        foreach (preg_split('/ +/', $expression) as $index => $field) {
            $names = match ($index) {
                4 => array_combine(explode(' ', 'jan feb mar apr may jun jul aug sep oct nov dec'), range(1, 12)),
                5 => array_combine(explode(' ', 'sun mon tue wed thu fri sat'), range(0, 6)),
                default => [],
            };
            [$min, $max] = $limits[$index];
            foreach (explode(',', $field) as $part) {
                if (preg_match('/\A([a-zA-Z0-9]+|\*|\?)(?:-([a-zA-Z0-9]+))?(?:\/([0-9]+))?\z/', $part, $match) !== 1) {
                    throw new InvalidArgumentException('Invalid cron field ' . $index);
                }
                $number = static function (string $value) use ($names, $min, $max, $index): int {
                    $value = $names[strtolower($value)] ?? $value;
                    if (!is_int($value) && preg_match('/\A[0-9]+\z/', $value) !== 1) {
                        throw new InvalidArgumentException('Invalid cron field ' . $index);
                    }
                    $number = is_int($value) ? $value : (int) Decimal::parse($value, (string) $max);
                    if ($number < $min || $number > $max) {
                        throw new InvalidArgumentException('Invalid cron range in field ' . $index);
                    }
                    return $number;
                };
                $wild   = $match[1] === '*' || $match[1] === '?';
                $start  = $wild ? $min : $number($match[1]);
                $end    = isset($match[2]) && $match[2] !== '' ? $number($match[2]) : ($wild ? $max : $start);
                if (isset($match[3])) {
                    Decimal::parse($match[3], Decimal::INT64_MAX, true);
                }
                if (($wild && ($match[2] ?? '') !== '') || $start > $end) {
                    throw new InvalidArgumentException('Invalid cron range or step in field ' . $index);
                }
            }
        }
    }
}
