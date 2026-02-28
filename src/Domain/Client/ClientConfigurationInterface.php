<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Dorpmaster\Nats\Client\WriteBufferPolicy;

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
}
