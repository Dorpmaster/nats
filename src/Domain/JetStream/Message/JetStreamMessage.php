<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Message;

final readonly class JetStreamMessage implements JetStreamMessageInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $subject,
        private string $payload,
        private array $headers,
        private string|null $replyTo,
        private int $sizeBytes,
    ) {
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getReplyTo(): string|null
    {
        return $this->replyTo;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }
}
