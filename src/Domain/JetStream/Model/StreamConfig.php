<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

final readonly class StreamConfig
{
    /**
     * @param list<string> $subjects
     */
    public function __construct(
        private string $name,
        private array $subjects,
        private string|null $storage = null,
        private int|null $replicas = null,
    ) {
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

        return $payload;
    }
}
