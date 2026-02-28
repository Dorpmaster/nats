<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client\WriteBuffer;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;

final readonly class OutboundFrame
{
    public function __construct(
        public NatsProtocolMessageInterface $message,
        public string $data,
        public int $bytes,
    ) {
    }
}
