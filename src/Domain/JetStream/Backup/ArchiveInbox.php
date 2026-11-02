<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Backup;

use Amp\{Cancellation,DeferredFuture};
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use SplQueue;
use Throwable;

/** @internal Subscription callbacks never block; bounded FIFO preserves the received wire order. */
final class ArchiveInbox
{
    private SplQueue $queue;
    private int $bytes                  = 0;
    private DeferredFuture|null $waiter = null;
    private Throwable|null $error       = null;
    private bool $closed                = false;
    public function __construct(private int $maxBytes, private int $maxMessages)
    {
        $this->queue = new SplQueue();
    }
    public function push(object $message): void
    {
        if ($this->closed || $this->error !== null) {
            return;
        }
        if (!method_exists($message, 'getPayload') || !is_string($message->getPayload()) || !method_exists($message, 'getReplyTo')) {
            $this->fail(new JetStreamApiException(0, 'Invalid snapshot message'));
            return;
        }
        $size = strlen($message->getPayload());
        if ($size > $this->maxBytes - $this->bytes || $this->queue->count() >= $this->maxMessages) {
            $this->fail(new JetStreamApiException(0, 'Snapshot receive buffer limit exceeded'));
            return;
        }
        $this->queue->enqueue($message);
        $this->bytes += $size;
        $this->waiter?->complete();
        $this->waiter = null;
    }
    public function fail(Throwable $error): void
    {
        $this->error ??= $error;
        $this->waiter?->complete();
        $this->waiter = null;
    }
    public function next(Cancellation $cancellation): object
    {
        while (true) {
            $cancellation->throwIfRequested();
            if ($this->error !== null) {
                throw $this->error;
            }
            if (!$this->queue->isEmpty()) {
                $message      = $this->queue->dequeue();
                $this->bytes -= strlen($message->getPayload());
                return $message;
            }
            $this->waiter = new DeferredFuture();
            $this->waiter->getFuture()->await($cancellation);
        }
    }
    public function close(): void
    {
        $this->closed = true;
        $this->queue  = new SplQueue();
        $this->bytes  = 0;
    }
}
