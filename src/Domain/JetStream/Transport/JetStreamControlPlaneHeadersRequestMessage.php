<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Transport;

use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\NatsMessageType;

final readonly class JetStreamControlPlaneHeadersRequestMessage implements NatsProtocolMessageInterface, HPubMessageInterface
{
    private int $payloadSize;
    private int $headersSize;
    private int $totalSize;

    public function __construct(
        private string $subject,
        private string $payload,
        private HeaderBugInterface $headers,
        private string|null $replyTo = null,
    ) {
        $this->payloadSize = strlen($this->payload);
        $this->headersSize = strlen((string) $this->headers) + 4;
        $this->totalSize   = $this->payloadSize + $this->headersSize;
    }

    public function __toString(): string
    {
        if ($this->replyTo === null) {
            return sprintf(
                '%s %s %d %d%s%s%s%s%s%s',
                $this->getType()->value,
                $this->getSubject(),
                $this->getHeadersSize(),
                $this->getTotalSize(),
                self::DELIMITER,
                (string) $this->getHeaders(),
                self::DELIMITER,
                self::DELIMITER,
                $this->getPayload(),
                self::DELIMITER,
            );
        }

        return sprintf(
            '%s %s %s %d %d%s%s%s%s%s%s',
            $this->getType()->value,
            $this->getSubject(),
            $this->getReplyTo(),
            $this->getHeadersSize(),
            $this->getTotalSize(),
            self::DELIMITER,
            (string) $this->getHeaders(),
            self::DELIMITER,
            self::DELIMITER,
            $this->getPayload(),
            self::DELIMITER,
        );
    }

    public function getType(): NatsMessageType
    {
        return NatsMessageType::HPUB;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getReplyTo(): string|null
    {
        return $this->replyTo;
    }

    public function getPayloadSize(): int
    {
        return $this->payloadSize;
    }

    public function getHeadersSize(): int
    {
        return $this->headersSize;
    }

    public function getTotalSize(): int
    {
        return $this->totalSize;
    }

    public function getHeaders(): HeaderBugInterface
    {
        return $this->headers;
    }
}
