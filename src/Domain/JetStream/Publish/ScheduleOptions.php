<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Dorpmaster\Nats\Domain\JetStream\Capability\Feature;
use Dorpmaster\Nats\Domain\JetStream\Internal\FastBatchMessage;
use Dorpmaster\Nats\Domain\JetStream\Internal\ResourceName;
use InvalidArgumentException;

final readonly class ScheduleOptions
{
    /** Server-side lifetime of a recurring definition that generates TTL-bearing messages. */
    public const int RECURRING_TTL_LIFETIME_SECONDS = 31_536_000;

    public int|null $ttlSeconds;
    public function __construct(
        public string $stream,
        public string $target,
        public MessageSchedule $schedule,
        public string|null $source = null,
        mixed $ttlSeconds = null,
        public ScheduleRollup|null $rollup = null,
    ) {
        ResourceName::validate($stream, 'stream');
        self::subject($target, 'target');
        if ($source !== null) {
            self::subject($source, 'source');
            if ($source === $target) {
                throw new InvalidArgumentException('Invalid source: source and target must differ');
            }
        }
        if ($ttlSeconds !== null && (!is_int($ttlSeconds) || $ttlSeconds < 1 || $ttlSeconds > 9223372036)) {
            throw new InvalidArgumentException('Invalid ttlSeconds: expected integer 1..9223372036');
        }
        $this->ttlSeconds = $ttlSeconds;
        $this->validateTiming();
    }
    /** @internal Recheck timing before enqueueing with the remaining operation bound. */
    public function validateTiming(int $pendingSeconds = 0): void
    {
        if ($this->ttlSeconds !== null) {
            $this->schedule->validateTtl($this->ttlSeconds, $pendingSeconds);
        }
    }
    public static function subject(string $subject, string $field): void
    {
        try {
            FastBatchMessage::subject($subject);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException('Invalid ' . $field . ': expected a concrete subject', 0, $error);
        }
    }
    public function validateDefinition(string $subject): void
    {
        self::subject($subject, 'definition subject');
        if ($subject === $this->target || $subject === $this->source) {
            throw new InvalidArgumentException('Invalid definition subject: definition, target and source must differ');
        }
    }
    public function feature(): Feature
    {
        return $this->schedule->isAt() && $this->source === null && $this->rollup === null ? Feature::MESSAGE_SCHEDULE_AT : Feature::MESSAGE_SCHEDULE_EXTENDED;
    }
    /** @return array<string,string> */
    public function toHeaders(): array
    {
        $headers = $this->schedule->toHeaders() + ['Nats-Schedule-Target' => $this->target, 'Nats-Expected-Stream' => $this->stream, 'Nats-Required-Api-Level' => (string) $this->feature()->minimumApiLevel()];
        if ($this->source !== null) {
            $headers['Nats-Schedule-Source'] = $this->source;
        }
        if ($this->ttlSeconds !== null) {
            $this->validateTiming();
            $headers['Nats-Schedule-TTL'] = $this->ttlSeconds . 's';
            if (!$this->schedule->isAt()) {
                // Bound all future firings; otherwise no positive TTL is safe for an indefinite schedule.
                $headers['Nats-TTL'] = self::RECURRING_TTL_LIFETIME_SECONDS . 's';
            }
        }
        if ($this->rollup !== null) {
            $headers['Nats-Schedule-Rollup'] = $this->rollup->value;
        }
        return $headers;
    }
}
