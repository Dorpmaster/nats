<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Domain\Client\InboundDispatchOverflowException;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

final class InboundDispatchScheduler
{
    /** @var list<array{generation:int, callback:\Closure():void}> */
    private array $pendingQueue                  = [];
    private int $activeCount                     = 0;
    private int $generation                      = 0;
    private bool $accepting                      = true;
    private DeferredFuture|null $deferredDrained = null;

    public function __construct(
        private readonly int $maxConcurrent,
        private readonly int $maxPending,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function dispatch(MsgMessageInterface|HMsgMessageInterface $message, \Closure $callback): void
    {
        if (!$this->accepting) {
            return;
        }

        if ($this->activeCount < $this->maxConcurrent) {
            $this->startDispatch($callback, $this->generation);

            return;
        }

        $pending = count($this->pendingQueue);
        if ($pending < $this->maxPending) {
            $this->pendingQueue[] = [
                'generation' => $this->generation,
                'callback' => $callback,
            ];
            $this->logger?->debug('inbound.dispatch.enqueue', [
                'active' => $this->activeCount,
                'pending' => count($this->pendingQueue),
            ]);

            return;
        }

        $this->logger?->warning('inbound.dispatch.overflow', [
            'active' => $this->activeCount,
            'pending' => $pending,
            'max_concurrent' => $this->maxConcurrent,
            'max_pending' => $this->maxPending,
            'message_type' => $message::class,
        ]);

        throw new InboundDispatchOverflowException(sprintf(
            'Inbound dispatch queue overflow: active=%d pending=%d max_concurrent=%d max_pending=%d',
            $this->activeCount,
            $pending,
            $this->maxConcurrent,
            $this->maxPending,
        ));
    }

    public function stop(): void
    {
        $this->accepting = false;
    }

    public function reset(): void
    {
        $this->generation++;
        $this->accepting    = true;
        $this->pendingQueue = [];
        $this->activeCount  = 0;
        if ($this->deferredDrained !== null && !$this->deferredDrained->isComplete()) {
            $this->deferredDrained->complete();
        }

        $this->deferredDrained = null;
    }

    public function drain(int|null $timeoutMs): void
    {
        $this->accepting = false;
        $this->logger?->debug('inbound.dispatch.drain.start', [
            'active' => $this->activeCount,
            'pending' => count($this->pendingQueue),
        ]);

        if ($this->activeCount === 0 && $this->pendingQueue === []) {
            $this->logger?->debug('inbound.dispatch.drain.success', [
                'active' => 0,
                'pending' => 0,
            ]);

            return;
        }

        if ($this->deferredDrained === null || $this->deferredDrained->isComplete()) {
            $this->deferredDrained = new DeferredFuture();
        }

        try {
            $timeout = ($timeoutMs ?? 10_000) / 1000;
            $this->deferredDrained->getFuture()->await(new TimeoutCancellation($timeout));

            $this->logger?->debug('inbound.dispatch.drain.success', [
                'active' => $this->activeCount,
                'pending' => count($this->pendingQueue),
            ]);
        } catch (Throwable $exception) {
            $this->logger?->warning('inbound.dispatch.drain.timeout', [
                'active' => $this->activeCount,
                'pending' => count($this->pendingQueue),
                'exception' => $exception,
            ]);
        }
    }

    public function getActiveCount(): int
    {
        return $this->activeCount;
    }

    public function getPendingCount(): int
    {
        return count($this->pendingQueue);
    }

    private function startDispatch(\Closure $callback, int $generation): void
    {
        $this->activeCount++;
        if ($this->deferredDrained === null || $this->deferredDrained->isComplete()) {
            $this->deferredDrained = new DeferredFuture();
        }
        $this->logger?->debug('inbound.dispatch.schedule', [
            'active' => $this->activeCount,
            'pending' => count($this->pendingQueue),
        ]);

        async(function () use ($callback, $generation): void {
            try {
                $callback();
            } catch (Throwable $exception) {
                $this->logger?->error('inbound.dispatch.callback_error', [
                    'exception' => $exception,
                ]);
            } finally {
                $this->completeDispatch($generation);
            }
        });
    }

    private function completeDispatch(int $generation): void
    {
        if ($generation !== $this->generation) {
            return;
        }

        if ($this->activeCount > 0) {
            $this->activeCount--;
        }

        $this->logger?->debug('inbound.dispatch.complete', [
            'active' => $this->activeCount,
            'pending' => count($this->pendingQueue),
        ]);

        $this->pumpPendingQueue();

        if ($this->activeCount === 0 && $this->pendingQueue === []) {
            if ($this->deferredDrained !== null && !$this->deferredDrained->isComplete()) {
                $this->deferredDrained->complete();
            }
        }
    }

    private function pumpPendingQueue(): void
    {
        while ($this->activeCount < $this->maxConcurrent && $this->pendingQueue !== []) {
            $entry = array_shift($this->pendingQueue);
            if (!is_array($entry)) {
                continue;
            }

            $this->startDispatch($entry['callback'], $entry['generation']);
        }
    }
}
