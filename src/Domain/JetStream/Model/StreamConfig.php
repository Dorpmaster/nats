<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

final readonly class StreamConfig
{
    /**
     * @param list<string> $subjects
     * @param list<StreamSource>|null $sources
     */
    public function __construct(
        private string $name,
        private array $subjects,
        private string|null $storage = null,
        private int|null $replicas = null,
        private bool|null $allowAtomicPublish = null,
        private bool|null $allowBatchPublish = null,
        private int|null $maxConsumers = null,
        private bool|null $allowMessageSchedules = null,
        private bool|null $allowMessageTtl = null,
        private StreamSource|null $mirror = null,
        private array|null $sources = null,
        private string|null $retention = null,
    ) {
        if ($sources !== null) {
            StreamSource::validateList($sources);
        }
        if ($mirror !== null && ($subjects !== [] || ($sources !== null && $sources !== []))) {
            throw new \InvalidArgumentException('Invalid mirror: subjects and sources must be empty');
        }
        if ($retention !== null && !in_array($retention, ['limits', 'interest', 'workqueue'], true)) {
            throw new \InvalidArgumentException('Invalid retention policy');
        }
        if ($maxConsumers !== null && $maxConsumers < -1) {
            throw new \InvalidArgumentException('Invalid maxConsumers: expected -1 or a nonnegative integer');
        }
    }

    public function getAllowAtomicPublish(): bool|null
    {
        return $this->allowAtomicPublish;
    }

    public function getAllowBatchPublish(): bool|null
    {
        return $this->allowBatchPublish;
    }

    public function getAllowMessageSchedules(): bool|null
    {
        return $this->allowMessageSchedules;
    }
    public function getAllowMessageTtl(): bool|null
    {
        return $this->allowMessageTtl;
    }
    public function getMaxConsumers(): int|null
    {
        return $this->maxConsumers;
    }

    public function getMirror(): StreamSource|null
    {
        return $this->mirror;
    }
    /** @return list<StreamSource>|null */
    public function getSources(): array|null
    {
        return $this->sources;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function getSubjects(): array
    {
        return $this->subjects;
    }

    public function getStorage(): string|null
    {
        return $this->storage;
    }

    public function getReplicas(): int|null
    {
        return $this->replicas;
    }

    public function toRequestPayload(): array
    {
        $payload = [
            'name' => $this->name,
            'subjects' => $this->subjects,
        ];

        if ($this->storage !== null) {
            $payload['storage'] = $this->storage;
        }

        if ($this->replicas !== null) {
            $payload['num_replicas'] = $this->replicas;
        }

        foreach (['allow_atomic' => $this->allowAtomicPublish, 'allow_batched' => $this->allowBatchPublish, 'max_consumers' => $this->maxConsumers, 'allow_msg_schedules' => $this->allowMessageSchedules, 'allow_msg_ttl' => $this->allowMessageTtl] as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        if ($this->mirror !== null) {
            $payload['mirror'] = $this->mirror->toRequestPayload();
        }
        if ($this->sources !== null) {
            $payload['sources'] = array_map(static fn (StreamSource $source): array => $source->toRequestPayload(), $this->sources);
        }
        if ($this->retention !== null) {
            $payload['retention'] = $this->retention;
        }
        return $payload;
    }
}
