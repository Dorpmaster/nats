<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Domain\Telemetry\MetricsCollectorInterface;

final class RecordingMetricsCollector implements MetricsCollectorInterface
{
    /** @var list<array{name: string, by: int, tags: array<string, scalar>}> */
    private array $increments = [];
    /** @var list<array{name: string, value: float, tags: array<string, scalar>}> */
    private array $observations = [];

    public function increment(string $counter, int $by = 1, array $tags = []): void
    {
        $this->increments[] = [
            'name' => $counter,
            'by' => $by,
            'tags' => $tags,
        ];
    }

    public function observe(string $metric, float $value, array $tags = []): void
    {
        $this->observations[] = [
            'name' => $metric,
            'value' => $value,
            'tags' => $tags,
        ];
    }

    /** @return list<array{name: string, by: int, tags: array<string, scalar>}> */
    public function increments(): array
    {
        return $this->increments;
    }

    /** @return list<array{name: string, value: float, tags: array<string, scalar>}> */
    public function observations(): array
    {
        return $this->observations;
    }

    public function countIncrements(string $name): int
    {
        return count(array_filter(
            $this->increments,
            static fn(array $item): bool => $item['name'] === $name,
        ));
    }
}
