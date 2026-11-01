<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Client\WriteBuffer\OutboundFrame;
use Dorpmaster\Nats\Client\WriteBuffer\FrameQueue;
use Dorpmaster\Nats\Domain\Client\SingleAttemptWriteBufferInterface;
use Dorpmaster\Nats\Domain\Client\RequestWriteException;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use SplObjectStorage;
use Dorpmaster\Nats\Domain\Client\WriteBufferOverflowException;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Domain\Telemetry\MetricsCollectorInterface;
use Dorpmaster\Nats\Domain\Telemetry\NullMetricsCollector;
use Dorpmaster\Nats\Telemetry\ProtocolLogContext;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

final class WriteBufferService implements SingleAttemptWriteBufferInterface
{
    private FrameQueue $queue;
    /** @var SplObjectStorage<OutboundFrame, int> Number of enqueues of the same snapshot. */
    private SplObjectStorage $accounted;
    private OutboundFrame|null $inFlight         = null;
    private int $writerEpoch                     = 0;
    private ConnectionInterface|null $connection = null;
    private DeferredCancellation $loopCancellation;
    private bool $writerRunning                  = false;
    private int $pendingMessages                 = 0;
    private int $pendingBytes                    = 0;
    private OutboundFrame|null $failedFrame      = null;
    private DeferredFuture|null $deferredDrained = null;
    /** @var \Closure(\Throwable):void|null */
    private \Closure|null $failureHandler = null;
    private bool $paused                  = false;

    public function __construct(
        private readonly int $maxMessages,
        private readonly int $maxBytes,
        private readonly WriteBufferPolicy $policy = WriteBufferPolicy::ERROR,
        private readonly LoggerInterface|null $logger = null,
        private readonly MetricsCollectorInterface|null $metricsCollector = null,
    ) {
        $this->initializeQueue();
    }

    public function setFailureHandler(\Closure|null $handler): void
    {
        $this->failureHandler = $handler;
    }

    public function start(ConnectionInterface $connection): void
    {
        $this->writerEpoch++;
        $this->connection = $connection;

        if ($this->writerRunning) {
            return;
        }

        $this->loopCancellation = new DeferredCancellation();
        if (!$this->paused) {
            $this->scheduleWriter();
        }
    }

    public function pause(): void
    {
        $this->writerEpoch++;
        $this->paused = true;
        $this->loopCancellation->cancel();
    }

    public function resume(): void
    {
        if (!$this->paused) {
            return;
        }

        $this->writerEpoch++;
        $this->paused           = false;
        $this->loopCancellation = new DeferredCancellation();
        $this->scheduleWriter();
    }

    public function detach(): void
    {
        $this->writerEpoch++;
        $this->connection = null;
        $this->loopCancellation->cancel();
    }

    public function stop(): void
    {
        $this->detach();
        $frames = iterator_to_array($this->accounted, false);
        foreach ($frames as $frame) {
            $frame->request?->fail(new ConnectionException('Write buffer stopped before request completion'));
        }
        $this->paused          = false;
        $this->failedFrame     = null;
        $this->pendingMessages = 0;
        $this->pendingBytes    = 0;
        $this->completeDrainIfNeeded();
        $this->queue->complete();
        $this->initializeQueue();
    }

    public function drain(int|null $timeoutMs = null): void
    {
        if ($this->paused) {
            $this->stop();

            return;
        }

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
                ...ProtocolLogContext::exception($exception),
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
                $this->getMetricsCollector()->increment('dropped_messages', 1, [
                    'direction' => 'outbound',
                    'policy' => WriteBufferPolicy::DROP_NEW->name,
                ]);
                return false;
            }

            $this->getMetricsCollector()->increment('write_buffer_overflow', 1, [
                'policy' => WriteBufferPolicy::ERROR->name,
            ]);
            throw new WriteBufferOverflowException(sprintf(
                'Write buffer overflow: pending_messages=%d max_messages=%d pending_bytes=%d max_bytes=%d',
                $this->pendingMessages,
                $this->maxMessages,
                $this->pendingBytes,
                $this->maxBytes,
            ));
        }

        $this->accounted[$frame] = ($this->accounted[$frame] ?? 0) + 1;
        $this->pendingMessages++;
        $this->pendingBytes += $frame->bytes;
        $this->queue->push($frame);
        $frame->request?->onInactive(function () use ($frame): void {
            if ($this->inFlight !== $frame) {
                $this->queue->remove($frame);
                $this->releaseFrame($frame);
            }
        });
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

    public function isRunning(): bool
    {
        return $this->writerRunning;
    }

    public function hasFailedFrame(): bool
    {
        return $this->failedFrame !== null;
    }

    private function scheduleWriter(): void
    {
        if ($this->writerRunning || $this->paused) {
            return;
        }

        $this->writerRunning = true;
        $epoch               = $this->writerEpoch;
        EventLoop::queue(function () use ($epoch): void {
            try {
                $this->writerLoop();
            } finally {
                $this->writerRunning = false;
                if ($epoch !== $this->writerEpoch && !$this->paused && $this->connection !== null && !$this->connection->isClosed()) {
                    $this->scheduleWriter();
                }
            }
        });
    }

    private function writerLoop(): void
    {
        while (true) {
            if ($this->paused) {
                return;
            }

            if ($this->failedFrame !== null) {
                $frame = $this->failedFrame;
            } else {
                if ($this->connection === null) {
                    return;
                }

                try {
                    $queue = $this->queue;
                    $frame = $queue->pop($this->loopCancellation->getCancellation());
                    if ($frame === null) {
                        return;
                    }
                } catch (CancelledException) {
                    return;
                }
            }

            $connection = $this->connection;
            if ($connection === null || $connection->isClosed()) {
                $this->failedFrame = $frame;

                return;
            }

            if ($frame->request !== null && !$frame->request->beginAttempt()) {
                $this->releaseFrame($frame);
                continue;
            }
            $this->inFlight = $frame;
            try {
                $connection->send($frame);
            } catch (\Throwable $exception) {
                $this->inFlight = null;
                if ($frame->request === null) {
                    $this->failedFrame = $frame;
                } else {
                    $this->releaseFrame($frame);
                    $frame->request->fail(new RequestWriteException($exception));
                }
                ($this->failureHandler)?->__invoke($exception);
                return;
            }
            $this->inFlight = null;
            $this->releaseFrame($frame);
        }
    }

    private function releaseFrame(OutboundFrame $frame): void
    {
        if ($this->failedFrame === $frame) {
            $this->failedFrame = null;
        }
        $count = $this->accounted[$frame] ?? 0;
        if ($count === 0) {
            return;
        }
        if ($count === 1) {
            unset($this->accounted[$frame]);
        } else {
            $this->accounted[$frame] = $count - 1;
        }
        $this->pendingMessages = max(0, $this->pendingMessages - 1);
        $this->pendingBytes    = max(0, $this->pendingBytes - $frame->bytes);
        $this->completeDrainIfNeeded();
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
        $this->queue            = new FrameQueue();
        $this->accounted        = new SplObjectStorage();
        $this->loopCancellation = new DeferredCancellation();
    }

    private function getMetricsCollector(): MetricsCollectorInterface
    {
        return $this->metricsCollector ?? new NullMetricsCollector();
    }
}
