<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Domain\Client\OutboundFrame;
use Dorpmaster\Nats\Domain\Client\WriteBufferInterface;
use Dorpmaster\Nats\Domain\Client\WriteBufferOverflowException;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

final class WriteBufferService implements WriteBufferInterface
{
    private Queue $queue;
    /** @var ConcurrentIterator<OutboundFrame> */
    private ConcurrentIterator $iterator;
    private ConnectionInterface|null $connection = null;
    private DeferredCancellation $loopCancellation;
    private bool $writerRunning                  = false;
    private int $pendingMessages                 = 0;
    private int $pendingBytes                    = 0;
    private OutboundFrame|null $failedFrame      = null;
    private DeferredFuture|null $deferredDrained = null;
    /** @var \Closure(\Throwable):void|null */
    private \Closure|null $failureHandler = null;

    public function __construct(
        private readonly int $maxMessages,
        private readonly int $maxBytes,
        private readonly WriteBufferPolicy $policy = WriteBufferPolicy::ERROR,
        private readonly LoggerInterface|null $logger = null,
    ) {
        $this->initializeQueue();
    }

    public function setFailureHandler(\Closure|null $handler): void
    {
        $this->failureHandler = $handler;
    }

    public function start(ConnectionInterface $connection): void
    {
        $this->connection       = $connection;
        $this->loopCancellation = new DeferredCancellation();
        $this->scheduleWriter();
    }

    public function detach(): void
    {
        $this->connection = null;
        $this->loopCancellation->cancel();
    }

    public function stop(): void
    {
        $this->detach();
        $this->failedFrame     = null;
        $this->pendingMessages = 0;
        $this->pendingBytes    = 0;
        $this->completeDrainIfNeeded();
        $this->queue->complete();
        $this->initializeQueue();
    }

    public function drain(int|null $timeoutMs = null): void
    {
        $this->scheduleWriter();

        if ($this->pendingMessages === 0) {
            $this->stop();

            return;
        }

        $this->deferredDrained ??= new DeferredFuture();
        try {
            $timeout = ($timeoutMs ?? 10_000) / 1000;
            $this->deferredDrained->getFuture()->await(new TimeoutCancellation($timeout));
        } catch (\Throwable $exception) {
            $this->logger?->warning('Write buffer drain timeout', [
                'exception' => $exception,
                'pending_messages' => $this->pendingMessages,
                'pending_bytes' => $this->pendingBytes,
            ]);
        }

        $this->stop();
    }

    public function enqueue(OutboundFrame $frame): bool
    {
        if (
            $this->pendingMessages >= $this->maxMessages
            || ($this->pendingBytes + $frame->bytes) > $this->maxBytes
        ) {
            if ($this->policy === WriteBufferPolicy::DROP_NEW) {
                return false;
            }

            throw new WriteBufferOverflowException(sprintf(
                'Write buffer overflow: pending_messages=%d max_messages=%d pending_bytes=%d max_bytes=%d',
                $this->pendingMessages,
                $this->maxMessages,
                $this->pendingBytes,
                $this->maxBytes,
            ));
        }

        $this->queue->push($frame);
        $this->pendingMessages++;
        $this->pendingBytes += $frame->bytes;
        $this->scheduleWriter();

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

    private function scheduleWriter(): void
    {
        if ($this->writerRunning) {
            return;
        }

        $this->writerRunning = true;
        EventLoop::queue(function (): void {
            try {
                $this->writerLoop();
            } finally {
                $this->writerRunning = false;
            }
        });
    }

    private function writerLoop(): void
    {
        while (true) {
            if ($this->failedFrame !== null) {
                $frame = $this->failedFrame;
            } else {
                if ($this->connection === null) {
                    return;
                }

                try {
                    $iterator = $this->iterator;
                    if (!$iterator->continue($this->loopCancellation->getCancellation())) {
                        return;
                    }
                    $frame = $iterator->getValue();
                } catch (CancelledException) {
                    return;
                }
            }

            $connection = $this->connection;
            if ($connection === null || $connection->isClosed()) {
                $this->failedFrame = $frame;

                return;
            }

            try {
                $connection->send($frame->message);
            } catch (\Throwable $exception) {
                $this->failedFrame = $frame;
                ($this->failureHandler)?->__invoke($exception);

                return;
            }

            $this->failedFrame     = null;
            $this->pendingMessages = max(0, $this->pendingMessages - 1);
            $this->pendingBytes    = max(0, $this->pendingBytes - $frame->bytes);
            $this->completeDrainIfNeeded();
        }
    }

    private function completeDrainIfNeeded(): void
    {
        if ($this->pendingMessages > 0) {
            return;
        }

        $this->pendingBytes = 0;
        if ($this->deferredDrained !== null && !$this->deferredDrained->isComplete()) {
            $this->deferredDrained->complete();
        }

        $this->deferredDrained = null;
    }

    private function initializeQueue(): void
    {
        $this->queue            = new Queue($this->maxMessages);
        $this->iterator         = $this->queue->iterate();
        $this->loopCancellation = new DeferredCancellation();
    }
}
