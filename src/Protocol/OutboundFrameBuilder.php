<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol;

use Dorpmaster\Nats\Domain\Client\OutboundFrame;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;

final class OutboundFrameBuilder
{
    public function build(NatsProtocolMessageInterface $message): OutboundFrame
    {
        $data = (string) $message;

        return new OutboundFrame(
            message: $message,
            data: $data,
            bytes: strlen($data),
        );
    }
}
