<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\ResourceName;
use InvalidArgumentException;

final readonly class StreamSource
{
    public int|null $startSequence;
    public function __construct(
        public string $name,
        public StreamSourceConsumer|null $consumer = null,
        mixed $startSequence = null,
        public string|null $startTime = null,
        public string|null $filterSubject = null,
    ) {
        ResourceName::validate($name, 'source.name');
        if ($startSequence !== null && (!is_int($startSequence) || $startSequence < 0)) {
            throw new InvalidArgumentException('Invalid source.startSequence: expected a nonnegative PHP integer');
        }
        if ($consumer !== null && ($startSequence !== null || $startTime !== null || $filterSubject !== null)) {
            throw new InvalidArgumentException('Invalid source.consumer: startSequence, startTime and filterSubject must be omitted');
        }
        if ($startSequence !== null && $startTime !== null) {
            throw new InvalidArgumentException('Invalid source: startSequence and startTime are mutually exclusive');
        }
        if ($startTime !== null) {
            NanoTimestamp::fromRfc3339($startTime);
        }
        if ($filterSubject !== null) {
            new StreamConfigPatch(subjects:[$filterSubject]);
        }
        $this->startSequence = $startSequence;
    }
    /** @param list<StreamSource> $sources */
    public static function validateList(array $sources): void
    {
        if (!array_is_list($sources)) {
            throw new InvalidArgumentException('Invalid sources: expected a list');
        }
        $seen = [];
        foreach ($sources as $source) {
            if (!$source instanceof self) {
                throw new InvalidArgumentException('Invalid sources: expected StreamSource elements');
            }
            if ($source->consumer !== null) {
                $key = $source->name . '\0' . $source->consumer->name;
                if (isset($seen[$key])) {
                    throw new InvalidArgumentException('Invalid sources: duplicate stream and consumer pair');
                }
                $seen[$key] = true;
            }
        }
    }
    /** @return array<string,mixed> */
    public function toRequestPayload(): array
    {
        $payload = ['name' => $this->name];
        if ($this->consumer !== null) {
            $payload['consumer'] = $this->consumer->toRequestPayload();
        }
        if ($this->startSequence !== null && $this->startSequence !== 0) {
            $payload['opt_start_seq'] = $this->startSequence;
        }
        if ($this->startTime !== null) {
            $payload['opt_start_time'] = $this->startTime;
        }
        if ($this->filterSubject !== null) {
            $payload['filter_subject'] = $this->filterSubject;
        }
        return $payload;
    }
}
