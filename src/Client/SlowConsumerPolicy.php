<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

enum SlowConsumerPolicy: string
{
    case ERROR    = 'ERROR';
    case DROP_NEW = 'DROP_NEW';
}
