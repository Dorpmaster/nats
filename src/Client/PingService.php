<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;
use Dorpmaster\Nats\Domain\Client\PingServiceInterface;
use Dorpmaster\Nats\Domain\Telemetry\MetricsCollectorInterface;
use Dorpmaster\Nats\Domain\Telemetry\NullMetricsCollector;
use Dorpmaster\Nats\Domain\Telemetry\TimeProviderInterface;
use Dorpmaster\Nats\Telemetry\MonotonicTimeProvider;
use Revolt\EventLoop;

final class PingService implements PingServiceInterface
{
    private bool $running                                  = false;
    private DeferredCancellation|null $loopCancellation    = null;
    private DeferredCancellation|null $timeoutCancellation = null;
    private bool $awaitingPong                             = false;
    private int $lastPingSentAtMs                          = 0;
    /** @var \Closure():void|null */
    private \Closure|null $sendPing = null;
    /** @var \Closure():void|null */
    private \Closure|null $onPingTimeout = null;

    public function __construct(
        private readonly DelayStrategyInterface $delayStrategy,
        private readonly int $pingIntervalMs,
        private readonly int $pingTimeoutMs,
        private readonly MetricsCollectorInterface|null $metricsCollector = null,
        private readonly TimeProviderInterface|null $timeProvider = null,
    ) {
    }

    public function start(\Closure $sendPing, \Closure $onPingTimeout): void
    {
        if ($this->running) {
            return;
        }

        $this->sendPing         = $sendPing;
        $this->onPingTimeout    = $onPingTimeout;
        $this->loopCancellation = new DeferredCancellation();
        $this->running          = true;

        EventLoop::queue(function (): void {
            try {
                $this->loop();
            } finally {
                $this->running = false;
            }
        });
    }

    public function stop(): void
    {
        $this->loopCancellation?->cancel();
        $this->timeoutCancellation?->cancel();
        $this->loopCancellation    = null;
        $this->timeoutCancellation = null;
        $this->awaitingPong        = false;
        $this->running             = false;
    }

    public function onPongReceived(): void
    {
        if (!$this->awaitingPong) {
            return;
        }

        $this->awaitingPong = false;
        $rttMs              = max(0, $this->getTimeProvider()->nowMs() - $this->lastPingSentAtMs);
        $this->getMetricsCollector()->observe('ping_rtt_ms', $rttMs, [
            'unit' => 'ms',
        ]);

        $this->timeoutCancellation?->cancel();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    private function loop(): void
    {
        while (true) {
            $loopCancellation = $this->loopCancellation;
            if ($loopCancellation === null) {
                return;
            }

            $sendPing = $this->sendPing;
            if ($sendPing === null) {
                return;
            }

            $sendPing();
            $this->awaitingPong        = true;
            $this->lastPingSentAtMs    = $this->getTimeProvider()->nowMs();
            $this->timeoutCancellation = new DeferredCancellation();

            try {
                $this->delayStrategy->delay(
                    $this->pingTimeoutMs,
                    new CompositeCancellation(
                        $loopCancellation->getCancellation(),
                        $this->timeoutCancellation->getCancellation(),
                    ),
                );
            } catch (CancelledException) {
                if ($loopCancellation->getCancellation()->isRequested()) {
                    return;
                }
            }

            if ($this->awaitingPong) {
                $this->awaitingPong = false;
                $this->getMetricsCollector()->increment('ping_timeouts', 1, [
                    'cause' => 'timeout',
                ]);

                $onPingTimeout = $this->onPingTimeout;
                $onPingTimeout?->__invoke();
            }

            $this->timeoutCancellation = null;

            try {
                $this->delayStrategy->delay($this->pingIntervalMs, $loopCancellation->getCancellation());
            } catch (CancelledException) {
                return;
            }
        }
    }

    private function getMetricsCollector(): MetricsCollectorInterface
    {
        return $this->metricsCollector ?? new NullMetricsCollector();
    }

    private function getTimeProvider(): TimeProviderInterface
    {
        return $this->timeProvider ?? new MonotonicTimeProvider();
    }
}
