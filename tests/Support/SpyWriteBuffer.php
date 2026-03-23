<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Closure;
use Dorpmaster\Nats\Client\WriteBuffer\OutboundFrame;
use Dorpmaster\Nats\Domain\Client\WriteBufferInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;

final class SpyWriteBuffer implements WriteBufferInterface
{
    private bool $started                = false;
    private bool $running                = false;
    private bool $paused                 = false;
    private bool $detached               = false;
    private bool $stopped                = false;
    private int $pendingMessages         = 0;
    private int $pendingBytes            = 0;
    private int $drainCalls              = 0;
    private Closure|null $failureHandler = null;

    public function start(ConnectionInterface $connection): void
    {
        $this->started  = true;
        $this->running  = true;
        $this->detached = false;
        $this->stopped  = false;
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused  = false;
        $this->running = true;
    }

    public function detach(): void
    {
        $this->detached = true;
        $this->running  = false;
    }

    public function stop(): void
    {
        $this->stopped         = true;
        $this->detached        = true;
        $this->running         = false;
        $this->paused          = false;
        $this->pendingMessages = 0;
        $this->pendingBytes    = 0;
    }

    public function drain(int|null $timeoutMs = null): void
    {
        $this->drainCalls++;
    }

    public function enqueue(OutboundFrame $frame): bool
    {
        $this->pendingMessages++;
        $this->pendingBytes += $frame->bytes;

        return true;
    }

    public function getPendingMessages(): int
    {
        return $this->pendingMessages;
    }

    public function getPendingBytes(): int
    {
        return $this->pendingBytes;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function hasFailedFrame(): bool
    {
        return false;
    }

    public function setFailureHandler(Closure|null $handler): void
    {
        $this->failureHandler = $handler;
    }

    public function wasStarted(): bool
    {
        return $this->started;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function isDetached(): bool
    {
        return $this->detached;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function drainCalls(): int
    {
        return $this->drainCalls;
    }
}
