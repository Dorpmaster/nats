<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Metadata;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\NatsMessageType;

final class PingMessage implements NatsProtocolMessageInterface
{
    public function __toString(): string
    {
        return sprintf(
            '%s%s',
            $this->getType()->value,
            NatsProtocolMessageInterface::DELIMITER,
        );
    }

    public function getType(): NatsMessageType
    {
        return NatsMessageType::PING;
    }

    public function getPayload(): string
    {
        return  '';
    }
}
