<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Dorpmaster\Nats\Client\WriteBuffer\OutboundFrame;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;

interface WriteBufferInterface
{
    public function start(ConnectionInterface $connection): void;

    public function pause(): void;

    public function resume(): void;

    public function detach(): void;

    public function stop(): void;

    public function drain(int|null $timeoutMs = null): void;

    /** Returns false when message was dropped due to DROP_NEW policy. */
    public function enqueue(OutboundFrame $frame): bool;

    public function getPendingMessages(): int;

    public function getPendingBytes(): int;

    public function isRunning(): bool;

    public function hasFailedFrame(): bool;

    /** @param \Closure(\Throwable):void|null $handler */
    public function setFailureHandler(\Closure|null $handler): void;
}
