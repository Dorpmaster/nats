<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ServerInfo;

interface MessageDispatcherInterface
{
    public function dispatch(NatsProtocolMessageInterface $message): NatsProtocolMessageInterface|null;

    public function getServerInfo(): ServerInfo|null;
}
