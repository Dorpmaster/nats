<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol;

enum NatsMessageType: string
{
    case INFO = 'INFO';
    case CONNECT = 'CONNECT';
    case PUB = 'PUB';
    case HPUB = 'HPUB';
    case SUB = 'SUB';
    case UNSUB = 'UNSUB';
    case MSG = 'MSG';
    case HMSG = 'HMSG';
    case PING = 'PING';
    case PONG = 'PONG';
    case OK = '+OK';
    case ERR = '-ERR';
}
