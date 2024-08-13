<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use InvalidArgumentException;

final readonly class PubMessage implements NatsProtocolMessageInterface, PubMessageInterface
{
    private int $payloadSize;

    public function __construct(
        private string      $subject,
        private string      $payload,
        private string|null $replyTo = null,
    )
    {
        $this->payloadSize = strlen($this->payload);

        if (empty($this->subject)) {
            throw new InvalidArgumentException('Subject must be non-empty string.');
        }

        if (($this->replyTo !== null) && empty($this->replyTo)) {
            throw new InvalidArgumentException('Reply-To must be non-empty string.');
        }
    }

    public function __toString(): string
    {
        if ($this->replyTo === null) {
            return sprintf(
                '%s %s %d%s%s%s',
                $this->getType()->value,
                $this->getSubject(),
                $this->getPayloadSize(),
                NatsProtocolMessageInterface::DELIMITER,
                $this->getPayload(),
                NatsProtocolMessageInterface::DELIMITER,
            );
        };

        return sprintf(
            '%s %s %s %d%s%s%s',
            $this->getType()->value,
            $this->getSubject(),
            $this->getReplyTo(),
            $this->getPayloadSize(),
            NatsProtocolMessageInterface::DELIMITER,
            $this->getPayload(),
            NatsProtocolMessageInterface::DELIMITER,
        );
    }

    public function getType(): NatsMessageType
    {
        return NatsMessageType::PUB;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getSubject(): string
    {
        return $this->subject;
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
