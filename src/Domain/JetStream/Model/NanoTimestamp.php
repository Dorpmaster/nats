<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use DateTimeImmutable;
use DateTimeZone;
use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;
use InvalidArgumentException;

/** Exact timestamp. DateTime conversion truncates the last three fractional digits. */
final readonly class NanoTimestamp
{
    private function __construct(private string $rfc3339, private string $unixNanoseconds)
    {
    }

    public static function fromUnixNanoseconds(string $nanoseconds): self
    {
        $digits  = Decimal::parse($nanoseconds, Decimal::INT64_MAX);
        $padded  = str_pad($digits, 10, '0', STR_PAD_LEFT);
        $seconds = substr($padded, 0, -9);
        $nanos   = substr($padded, -9);
        $date    = (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'));
        return new self($date->format('Y-m-d\TH:i:s') . '.' . $nanos . 'Z', $digits);
    }

    public static function fromRfc3339(string $value): self
    {
        if (preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.([0-9]{1,9}))?(Z|[+-]\d{2}:\d{2})\z/', $value, $parts) !== 1) {
            throw new InvalidArgumentException('Invalid RFC3339 nanosecond timestamp');
        }
        $nanos  = str_pad($parts[2], 9, '0');
        $offset = $parts[3] === 'Z' ? '+00:00' : $parts[3];
        if ((int) substr($offset, 1, 2) > 23 || (int) substr($offset, 4, 2) > 59) {
            throw new InvalidArgumentException('Invalid timestamp offset');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $parts[1] . '.' . substr($nanos, 0, 6) . $offset);
        if ($date === false || DateTimeImmutable::getLastErrors() !== false) {
            throw new InvalidArgumentException('Invalid timestamp calendar fields');
        }
        $seconds = $date->format('U');
        if (str_starts_with($seconds, '-')) {
            $magnitude = substr($seconds, 1);
            if ($nanos !== '000000000') {
                $magnitude = Decimal::decrement($magnitude);
                $nanos     = str_pad((string) (1_000_000_000 - (int) $nanos), 9, '0', STR_PAD_LEFT);
            }
            $exact = '-' . (ltrim($magnitude . $nanos, '0') ?: '0');
        } else {
            $exact = ltrim($seconds . $nanos, '0') ?: '0';
        }
        return new self($value, $exact);
    }

    public function getRfc3339(): string
    {
        return $this->rfc3339;
    }

    public function getUnixNanoseconds(): string
    {
        return $this->unixNanoseconds;
    }

    /** Truncates submicrosecond digits toward the start of the represented second. */
    public function toDateTimeImmutable(): DateTimeImmutable
    {
        $value = preg_replace_callback('/\.([0-9]+)/', static fn (array $parts): string => '.' . substr(str_pad($parts[1], 6, '0'), 0, 6), $this->rfc3339);
        return new DateTimeImmutable($value);
    }
}
