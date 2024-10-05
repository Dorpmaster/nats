<?php

declare(strict_types=1);


namespace Dorpmaster\Nats\Domain\Client;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;

interface MessageDispatcherInterface
{
    public function dispatch(NatsProtocolMessageInterface $message): NatsProtocolMessageInterface|null;
}
