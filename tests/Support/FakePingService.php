<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Domain\Client\PingServiceInterface;

final class FakePingService implements PingServiceInterface
{
    /** @var \Closure():void|null */
    private \Closure|null $onPingTimeout = null;
    private bool $running                = false;
    private int $startCalls              = 0;

    public function start(\Closure $sendPing, \Closure $onPingTimeout): void
    {
        $this->running = true;
        $this->startCalls++;
        $this->onPingTimeout = $onPingTimeout;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function onPongReceived(): void
    {
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function triggerTimeout(): void
    {
        ($this->onPingTimeout)?->__invoke();
    }

    public function startCalls(): int
    {
        return $this->startCalls;
    }
}
