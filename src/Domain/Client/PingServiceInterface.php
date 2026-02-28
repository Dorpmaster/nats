<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

interface PingServiceInterface
{
    /** @param \Closure():void $sendPing */
    public function start(\Closure $sendPing, \Closure $onPingTimeout): void;

    public function stop(): void;

    public function onPongReceived(): void;

    public function isRunning(): bool;
}
