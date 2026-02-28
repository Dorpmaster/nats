<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Telemetry;

final class NullMetricsCollector implements MetricsCollectorInterface
{
    public function increment(string $counter, int $by = 1, array $tags = []): void
    {
    }

    public function observe(string $metric, float $value, array $tags = []): void
    {
    }
}
