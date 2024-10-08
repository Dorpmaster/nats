<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;

final readonly class ErrMessage implements NatsProtocolMessageInterface
{
    public function __construct(
        private string $payload,
    ) {
    }

    public function __toString(): string
    {
        return sprintf(
            '%s %s%s',
            $this->getType()->value,
            $this->getPayload(),
            NatsProtocolMessageInterface::DELIMITER,
        );
    }

    public function getType(): NatsMessageType
    {
        return NatsMessageType::ERR;
    }

    public function getPayload(): string
    {
        return  $this->payload;
    }
}
