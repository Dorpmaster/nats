<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

enum ScheduleRollup: string
{
    case SUB = 'sub';
}
