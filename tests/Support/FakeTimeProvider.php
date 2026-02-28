<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Domain\Telemetry\TimeProviderInterface;

final class FakeTimeProvider implements TimeProviderInterface
{
    public function __construct(private int $nowMs = 0)
    {
    }

    public function nowMs(): int
    {
        return $this->nowMs;
    }

    public function advanceMs(int $delta): void
    {
        $this->nowMs += $delta;
    }
}
