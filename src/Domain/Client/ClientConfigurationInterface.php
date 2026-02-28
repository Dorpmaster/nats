<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Dorpmaster\Nats\Client\WriteBufferPolicy;
use Dorpmaster\Nats\Domain\Telemetry\MetricsCollectorInterface;
use Dorpmaster\Nats\Domain\Telemetry\TimeProviderInterface;

interface ClientConfigurationInterface
{
    public function getWaitForStatusTimeout(): float;

    public function isReconnectEnabled(): bool;

    public function getMaxReconnectAttempts(): int|null;

    public function getReconnectBackoffInitialMs(): int;

    public function getReconnectBackoffMaxMs(): int;

    public function getReconnectBackoffMultiplier(): float;

    public function getReconnectJitterFraction(): float;

    /** @return list<string> */
    public function getReconnectServers(): array;

    public function getMaxWriteBufferMessages(): int;

    public function getMaxWriteBufferBytes(): int;

    public function getWriteBufferPolicy(): WriteBufferPolicy;

    public function isBufferWhileReconnecting(): bool;

    public function getMetricsCollector(): MetricsCollectorInterface;

    public function isPingEnabled(): bool;

    public function getPingIntervalMs(): int;

    public function getPingTimeoutMs(): int;

    public function isPingReconnectOnTimeout(): bool;

    public function getTimeProvider(): TimeProviderInterface;
}
