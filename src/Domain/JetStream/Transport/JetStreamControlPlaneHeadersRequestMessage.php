<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Transport;

use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Internal\ValidatePublishTokens;
use Dorpmaster\Nats\Protocol\NatsMessageType;

final readonly class JetStreamControlPlaneHeadersRequestMessage implements NatsProtocolMessageInterface, HPubMessageInterface
{
    use ValidatePublishTokens;

    private int $payloadSize;

    public function __construct(
        private string $subject,
        private string $payload,
        private HeaderBugInterface $headers,
        private string|null $replyTo = null,
    ) {
        $this->validatePublishTokens($this->subject, $this->replyTo);

        $this->payloadSize = strlen($this->payload);
    }

    public function __toString(): string
    {
        $headers     = (string) $this->headers . self::DELIMITER . self::DELIMITER;
        $headerBytes = strlen($headers);
        $totalBytes  = $headerBytes + $this->payloadSize;
        $replyTo     = $this->replyTo === null ? '' : ' ' . $this->replyTo;

        return sprintf(
            '%s %s%s %d %d%s%s%s%s',
            $this->getType()->value,
            $this->subject,
            $replyTo,
            $headerBytes,
            $totalBytes,
            self::DELIMITER,
            $headers,
            $this->payload,
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
        return strlen((string) $this->headers) + 4;
    }

    public function getTotalSize(): int
    {
        return $this->getHeadersSize() + $this->payloadSize;
    }

    public function getHeaders(): HeaderBugInterface
    {
        return $this->headers;
    }
}
