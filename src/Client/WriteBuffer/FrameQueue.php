<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client\WriteBuffer;

use Amp\Cancellation;
use Amp\DeferredFuture;
use SplQueue;

/** A bounded-by-owner FIFO supporting immediate removal of cancelled requests. */
final class FrameQueue
{
    /** @var SplQueue<OutboundFrame> */
    private SplQueue $frames;
    private DeferredFuture|null $available = null;
    private bool $closed                   = false;

    public function __construct()
    {
        $this->frames = new SplQueue();
    }

    public function push(OutboundFrame $frame): void
    {
        $this->frames->enqueue($frame);
        $this->wake();
    }

    public function pop(Cancellation $cancellation): OutboundFrame|null
    {
        while ($this->frames->isEmpty()) {
            if ($this->closed) {
                return null;
            }
            $this->available ??= new DeferredFuture();
            $this->available->getFuture()->await($cancellation);
        }
        $cancellation->throwIfRequested();
        return $this->frames->dequeue();
    }

    public function remove(OutboundFrame $frame): void
    {
        foreach ($this->frames as $index => $queued) {
            if ($queued === $frame) {
                $this->frames->offsetUnset($index);
                return;
            }
        }
    }

    public function complete(): void
    {
        $this->closed = true;
        $this->frames = new SplQueue();
        $this->wake();
    }

    private function wake(): void
    {
        $available       = $this->available;
        $this->available = null;
        $available?->complete();
    }
}
