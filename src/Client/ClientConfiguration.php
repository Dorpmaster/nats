<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Dorpmaster\Nats\Domain\Client\ClientConfigurationInterface;

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
        private array $reconnectServers = [],
        private int $maxWriteBufferMessages = 10_000,
        private int $maxWriteBufferBytes = 5_000_000,
        private WriteBufferPolicy $writeBufferPolicy = WriteBufferPolicy::ERROR,
        private bool $bufferWhileReconnecting = false,
    ) {
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

    /** @return list<string> */
    public function getReconnectServers(): array
    {
        return $this->reconnectServers;
    }

    public function getMaxWriteBufferMessages(): int
    {
        return $this->maxWriteBufferMessages;
    }

    public function getMaxWriteBufferBytes(): int
    {
        return $this->maxWriteBufferBytes;
    }

    public function getWriteBufferPolicy(): WriteBufferPolicy
    {
        return $this->writeBufferPolicy;
    }

    public function isBufferWhileReconnecting(): bool
    {
        return $this->bufferWhileReconnecting;
    }
}
