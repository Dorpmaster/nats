<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Telemetry;

interface MetricsCollectorInterface
{
    /**
     * @param array<string, scalar> $tags
     */
    public function increment(string $counter, int $by = 1, array $tags = []): void;

    /**
     * @param array<string, scalar> $tags
     */
    public function observe(string $metric, float $value, array $tags = []): void;
}
