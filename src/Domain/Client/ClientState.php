<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

enum ClientState: string
{
    case NEW          = 'NEW';
    case CONNECTING   = 'CONNECTING';
    case CONNECTED    = 'CONNECTED';
    case RECONNECTING = 'RECONNECTING';
    case DRAINING     = 'DRAINING';
    case CLOSED       = 'CLOSED';
}
