<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

final readonly class StreamInfo
{
    /**
     * @param list<string> $subjects
     */
    public function __construct(
        private string $name,
        private array $subjects,
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
}
