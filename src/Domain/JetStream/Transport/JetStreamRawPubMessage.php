<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Transport;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\NatsMessageType;

final readonly class JetStreamRawPubMessage implements NatsProtocolMessageInterface, PubMessageInterface
{
    private int $payloadSize;

    public function __construct(
        private string $subject,
        private string $payload,
        private string|null $replyTo = null,
    ) {
        $this->payloadSize = strlen($payload);
    }

    public function __toString(): string
    {
        if ($this->replyTo === null) {
            return sprintf(
                '%s %s %d%s%s%s',
                $this->getType()->value,
                $this->getSubject(),
                $this->getPayloadSize(),
                self::DELIMITER,
                $this->getPayload(),
                self::DELIMITER,
            );
        }

        return sprintf(
            '%s %s %s %d%s%s%s',
            $this->getType()->value,
            $this->getSubject(),
            $this->getReplyTo(),
            $this->getPayloadSize(),
            self::DELIMITER,
            $this->getPayload(),
            self::DELIMITER,
        );
    }

    public function getType(): NatsMessageType
    {
        return NatsMessageType::PUB;
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
}
