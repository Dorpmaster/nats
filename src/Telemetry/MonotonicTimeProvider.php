<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Telemetry;

use Dorpmaster\Nats\Domain\Telemetry\TimeProviderInterface;

final class MonotonicTimeProvider implements TimeProviderInterface
{
    public function nowMs(): int
    {
        return (int) floor(hrtime(true) / 1_000_000);
    }
}
