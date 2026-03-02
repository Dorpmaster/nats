<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

enum ConsumeState: string
{
    case RUNNING  = 'running';
    case STOPPING = 'stopping';
    case DRAINING = 'draining';
    case STOPPED  = 'stopped';
}
