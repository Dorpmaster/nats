<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use DateTimeZone;
use Dorpmaster\Nats\Domain\JetStream\Internal\CronExpression;
use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;
use Dorpmaster\Nats\Domain\JetStream\Model\NanoTimestamp;
use InvalidArgumentException;

/** Immutable server schedule; every() takes whole seconds, never a PHP timer. */
final readonly class MessageSchedule
{
    /** Supported target-write lateness for TTL-bearing @at schedules, not a NATS latency guarantee. */
    public const int AT_EXECUTION_DELAY_SECONDS = 60;

    private function __construct(private string $expression, private bool $at, private string|null $timeZone = null)
    {
    }
    public static function at(string $timestamp, string|null $timeZone = null): self
    {
        if ($timeZone !== null) {
            throw new InvalidArgumentException('Invalid timeZone: only cron supports a named time zone');
        }
        try {
            $nanoseconds = NanoTimestamp::fromRfc3339($timestamp)->getUnixNanoseconds();
            Decimal::parse(ltrim($nanoseconds, '-'), str_starts_with($nanoseconds, '-') ? '9223372036854775808' : Decimal::INT64_MAX);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException('Invalid schedule timestamp: expected RFC3339', 0, $error);
        }
        return new self('@at ' . $timestamp, true);
    }
    /** @param int $seconds Positive whole seconds (validated before weak scalar coercion). */
    public static function every(mixed $seconds, string|null $timeZone = null): self
    {
        if ($timeZone !== null) {
            throw new InvalidArgumentException('Invalid timeZone: only cron supports a named time zone');
        }
        if (!is_int($seconds) || $seconds < 1 || $seconds > 9223372036) {
            throw new InvalidArgumentException('Invalid interval seconds: expected integer 1..9223372036');
        }
        $result = new self('@every ' . $seconds . 's', false);
        $result->toHeaders();
        return $result;
    }
    public static function cron(string $expression, string|null $timeZone = null): self
    {
        try {
            CronExpression::validate($expression);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException('Invalid cron expression: ' . $error->getMessage(), 0, $error);
        }
        if ($timeZone !== null && !in_array($timeZone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('Invalid timeZone: expected UTC or an IANA name');
        }
        return new self($expression, false, $timeZone);
    }
    public function isAt(): bool
    {
        return $this->at;
    }
    /** @internal Check expiration using the execution time, not merely the duration. */
    public function validateTtl(int $ttlSeconds, int $pendingSeconds = 0): void
    {
        // Include the complete queue/admission deadline. A past @at can execute now.
        $latestSeconds = time() + 1 + $pendingSeconds + ($this->at ? 0 : ScheduleOptions::RECURRING_TTL_LIFETIME_SECONDS);
        if ($latestSeconds > intdiv(PHP_INT_MAX, 1_000_000_000)) {
            throw new InvalidArgumentException('Invalid ttlSeconds: schedule lifetime exceeds the server nanosecond clock');
        }
        $execution = $latestSeconds * 1_000_000_000;
        if ($this->at) {
            $execution = max($execution, (int) NanoTimestamp::fromRfc3339(substr($this->expression, 4))->getUnixNanoseconds());
        } elseif (str_starts_with($this->expression, '@every ') && (int) substr($this->expression, 7, -1) >= ScheduleOptions::RECURRING_TTL_LIFETIME_SECONDS) {
            throw new InvalidArgumentException('Invalid ttlSeconds: interval must be shorter than the bounded definition lifetime');
        }
        // Registration's deadline does not bound a future @at execution. Reserve its separate
        // supported lateness window before dividing; neither the timestamp nor the sum may overflow.
        $headroom         = PHP_INT_MAX - $execution;
        $executionReserve = $this->at ? self::AT_EXECUTION_DELAY_SECONDS * 1_000_000_000 : 0;
        if ($headroom < $executionReserve || $ttlSeconds > intdiv($headroom - $executionReserve, 1_000_000_000)) {
            throw new InvalidArgumentException('Invalid ttlSeconds: execution time, delay reserve and TTL exceed the server nanosecond clock');
        }
    }
    /** @return array<string,string> */
    public function toHeaders(): array
    {
        if (str_starts_with($this->expression, '@every ') && time() + (int) substr($this->expression, 7, -1) > 9223372035) {
            throw new InvalidArgumentException('Invalid interval: next firing exceeds the server nanosecond clock');
        }
        return ['Nats-Schedule' => $this->expression] + ($this->timeZone === null ? [] : ['Nats-Schedule-Time-Zone' => $this->timeZone]);
    }
}
