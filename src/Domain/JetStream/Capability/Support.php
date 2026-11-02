<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Capability;

enum Support: string
{
    case SUPPORTED   = 'supported';
    case UNSUPPORTED = 'unsupported';
    case UNKNOWN     = 'unknown';
}
