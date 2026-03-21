<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Dorpmaster\Nats\Domain\Client\ClientConfigurationInterface;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Domain\Telemetry\MetricsCollectorInterface;
use Dorpmaster\Nats\Domain\Telemetry\NullMetricsCollector;
use Dorpmaster\Nats\Domain\Telemetry\TimeProviderInterface;
use InvalidArgumentException;
use Dorpmaster\Nats\Telemetry\MonotonicTimeProvider;

final readonly class ClientConfiguration implements ClientConfigurationInterface
{
    public function __construct(
        private float $waitForStatusTimeout = 10,
        private bool $reconnectEnabled = false,
        private int|null $maxReconnectAttempts = 10,
        private int $reconnectBackoffInitialMs = 50,
        private int $reconnectBackoffMaxMs = 1_000,
        private float $reconnectBackoffMultiplier = 2.0,
        private float $reconnectJitterFraction = 0.2,
        private array $servers = [],
        private int $deadServerCooldownMs = 2_000,
        private int $maxWriteBufferMessages = 10_000,
        private int $maxWriteBufferBytes = 5_000_000,
        private int $maxInboundDispatchConcurrency = 64,
        private int $maxPendingInboundDispatch = 1_024,
        private WriteBufferPolicy $writeBufferPolicy = WriteBufferPolicy::ERROR,
        private bool $bufferWhileReconnecting = false,
        private MetricsCollectorInterface|null $metricsCollector = null,
        private bool $pingEnabled = false,
        private int $pingIntervalMs = 30_000,
        private int $pingTimeoutMs = 2_000,
        private bool $pingReconnectOnTimeout = true,
        private TimeProviderInterface|null $timeProvider = null,
    ) {
        if ($this->maxInboundDispatchConcurrency <= 0) {
            throw new InvalidArgumentException('maxInboundDispatchConcurrency must be greater than 0');
        }

        if ($this->maxPendingInboundDispatch <= 0) {
            throw new InvalidArgumentException('maxPendingInboundDispatch must be greater than 0');
        }
    }

    public function getWaitForStatusTimeout(): float
    {
        return $this->waitForStatusTimeout;
    }

    public function isReconnectEnabled(): bool
    {
        return $this->reconnectEnabled;
    }

    public function getMaxReconnectAttempts(): int|null
    {
        return $this->maxReconnectAttempts;
    }

    public function getReconnectBackoffInitialMs(): int
    {
        return $this->reconnectBackoffInitialMs;
    }

    public function getReconnectBackoffMaxMs(): int
    {
        return $this->reconnectBackoffMaxMs;
    }

    public function getReconnectBackoffMultiplier(): float
    {
        return $this->reconnectBackoffMultiplier;
    }

    public function getReconnectJitterFraction(): float
    {
        return $this->reconnectJitterFraction;
    }

    /** @return list<ServerAddress> */
    public function getServers(): array
    {
        return $this->servers;
    }

    public function getDeadServerCooldownMs(): int
    {
        return $this->deadServerCooldownMs;
    }

    public function getMaxWriteBufferMessages(): int
    {
        return $this->maxWriteBufferMessages;
    }

    public function getMaxWriteBufferBytes(): int
    {
        return $this->maxWriteBufferBytes;
    }

    public function getMaxInboundDispatchConcurrency(): int
    {
        return $this->maxInboundDispatchConcurrency;
    }

    public function getMaxPendingInboundDispatch(): int
    {
        return $this->maxPendingInboundDispatch;
    }

    public function getWriteBufferPolicy(): WriteBufferPolicy
    {
        return $this->writeBufferPolicy;
    }

    public function isBufferWhileReconnecting(): bool
    {
        return $this->bufferWhileReconnecting;
    }

    public function getMetricsCollector(): MetricsCollectorInterface
    {
        return $this->metricsCollector ?? new NullMetricsCollector();
    }

    public function isPingEnabled(): bool
    {
        return $this->pingEnabled;
    }

    public function getPingIntervalMs(): int
    {
        return $this->pingIntervalMs;
    }

    public function getPingTimeoutMs(): int
    {
        return $this->pingTimeoutMs;
    }

    public function isPingReconnectOnTimeout(): bool
    {
        return $this->pingReconnectOnTimeout;
    }

    public function getTimeProvider(): TimeProviderInterface
    {
        return $this->timeProvider ?? new MonotonicTimeProvider();
    }
}
