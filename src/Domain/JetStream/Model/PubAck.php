<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use InvalidArgumentException;

final readonly class PubAck
{
    public function __construct(
        private string $stream,
        private int $seq,
        private bool $duplicate = false,
    ) {
        if ($this->stream === '') {
            throw new InvalidArgumentException('PubAck stream must not be empty');
        }

        if ($this->seq <= 0) {
            throw new InvalidArgumentException('PubAck seq must be greater than zero');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $stream = $data['stream'] ?? null;
        if (!is_string($stream) || $stream === '') {
            throw new InvalidArgumentException('PubAck response does not contain a valid stream');
        }

        $seq = $data['seq'] ?? null;
        if (!is_int($seq) && !is_numeric($seq)) {
            throw new InvalidArgumentException('PubAck response does not contain a valid seq');
        }

        $sequence = (int) $seq;
        if ($sequence <= 0) {
            throw new InvalidArgumentException('PubAck seq must be greater than zero');
        }

        $duplicate = $data['duplicate'] ?? false;
        if (!is_bool($duplicate)) {
            throw new InvalidArgumentException('PubAck duplicate flag must be boolean');
        }

        return new self($stream, $sequence, $duplicate);
    }

    public function getStream(): string
    {
        return $this->stream;
    }

    public function getSeq(): int
    {
        return $this->seq;
    }

    public function isDuplicate(): bool
    {
        return $this->duplicate;
    }
}
