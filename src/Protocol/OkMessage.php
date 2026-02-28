<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;

final class OkMessage implements NatsProtocolMessageInterface
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
        return NatsMessageType::OK;
    }

    public function getPayload(): string
    {
        return  '';
    }
}
