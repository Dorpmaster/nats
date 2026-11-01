<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Message;

use Dorpmaster\Nats\Domain\JetStream\Internal\AckSubjectMetadata;

final readonly class JetStreamMessage implements JetStreamMessageInterface, JetStreamMessageMetadataInterface, JetStreamMessageHeadersInterface
{
    private MessageMetadata|null $metadata;
    /** @var array<string, list<string>> */
    private array $headerValues;

    /**
     * @param array<string, string> $headers
     * @param array<string, list<string>>|null $headerValues
     */
    public function __construct(
        private string $subject,
        private string $payload,
        private array $headers,
        private string|null $replyTo,
        private int $sizeBytes,
        private int|null $deliveryCount = null,
        array|null $headerValues = null,
    ) {
        $this->metadata = AckSubjectMetadata::parse($replyTo);
        $snapshot       = [];
        foreach ($headerValues ?? array_map(static fn (string $value): array => [$value], $headers) as $name => $values) {
            $snapshot[$name] = [];
            foreach ($values as $value) {
                $snapshot[$name][] = $value;
            }
        }
        $this->headerValues = $snapshot;
    }

    public function getMetadata(): MessageMetadata|null
    {
        return $this->metadata;
    }

    public function getHeaderValues(): array
    {
        return $this->headerValues;
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

    public function getDeliveryCount(): int|null
    {
        return $this->deliveryCount;
    }
}
