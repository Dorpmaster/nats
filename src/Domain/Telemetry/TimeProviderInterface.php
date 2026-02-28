<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Telemetry;

interface TimeProviderInterface
{
    public function nowMs(): int;
}
