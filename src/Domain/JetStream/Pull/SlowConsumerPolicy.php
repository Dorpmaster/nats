<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

enum SlowConsumerPolicy: string
{
    case ERROR    = 'error';
    case DROP_NEW = 'drop_new';
}
