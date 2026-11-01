<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client\WriteBuffer;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\NatsMessageType;

final readonly class OutboundFrame implements NatsProtocolMessageInterface
{
    public function __construct(
        public NatsProtocolMessageInterface $message,
        public string $data,
        public int $bytes,
    ) {
    }

    public function __toString(): string
    {
        return $this->data;
    }

    public function getType(): NatsMessageType
    {
        return $this->message->getType();
    }

    public function getPayload(): string
    {
        return $this->message->getPayload();
    }
}
