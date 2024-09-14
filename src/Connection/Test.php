<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Connection;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

final class Test
{
    private string $status = 'CLOSED';
    private DeferredFuture|null $opening = null;

    public function __construct(
        private LoggerInterface $logger,
    )
    {
    }


    /**
     * @return Future<int>
     */
    public function open(int $sequence): Future
    {
        $this->logger->info('Starting: ' . $sequence);
        if ($this->opening !== null) {
            $this->logger->warning('In progress');

            return $this->opening->getFuture();
        }

        $this->logger->info('Creating Future: ' . $sequence);
        $this->opening ??= new DeferredFuture();

        EventLoop::queue(function () use ($sequence) {
            $this->waitStatus('TEST');

            $this->logger->info('Tick!!!');
            $this->opening->complete($sequence);
            $this->logger->info('Clear: ' . $sequence);
            $this->opening = null;
        });

        return $this->opening->getFuture();
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function waitStatus(string $status): void
    {
        if ($this->status === $status) {
            return;
        }

        $suspension = EventLoop::getSuspension();
        $cancellation = new TimeoutCancellation(10);
        $id = $cancellation?->subscribe(static fn(CancelledException $exception) => $suspension->throw($exception));
        $watcherId = EventLoop::repeat(0.1, function (string $watcherId) use ($suspension, $status) {
            $this->logger->debug('Waiting for the status: ' . $status);
            if ($status === $this->status) {
                $suspension->resume();
                EventLoop::cancel($watcherId);
            }
        });

        try {
            $this->logger->debug('Starting to wait');
            $suspension->suspend();
            $this->logger->debug('Here we go');
        } finally {
            $this->logger->debug('Cleaning');
            $cancellation->unsubscribe($id);
            EventLoop::cancel($watcherId);
        }
    }
}
