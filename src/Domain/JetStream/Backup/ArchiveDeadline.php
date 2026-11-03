<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Backup;

use Amp\{Cancellation,CancelledException,CompositeCancellation,DeferredCancellation,NullCancellation,TimeoutException};
use Closure;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use InvalidArgumentException;
use Revolt\EventLoop;

/** @internal Explicitly disposed monotonic deadline, including synchronous queue-attempt checks. */
final readonly class ArchiveDeadline implements Cancellation
{
    private int $end;
    private string $timer;
    private Cancellation $listeners;

    public function __construct(mixed $timeoutMs, Cancellation|null $cancellation)
    {
        $now         = hrtime(true);
        $timeoutMs ??= 30000;
        if (!is_int($timeoutMs) || $timeoutMs <= 0 || $timeoutMs > intdiv(PHP_INT_MAX - $now, 1000000)) {
            throw new InvalidArgumentException('Invalid timeoutMs');
        }
        $this->end       = $now + $timeoutMs * 1000000;
        $clock           = new DeferredCancellation();
        $this->listeners = new CompositeCancellation($cancellation ?? new NullCancellation(), $clock->getCancellation());
        $this->timer     = EventLoop::delay($timeoutMs / 1000, static fn () => $clock->cancel(new TimeoutException('JetStream archive deadline expired')));
        EventLoop::unreference($this->timer);
    }
    public function remaining(): float
    {
        if (hrtime(true) >= $this->end) {
            throw new JetStreamTimeoutException('JetStream archive deadline expired');
        }
        $this->throwIfRequested();
        return max(0.000000001, ($this->end - hrtime(true)) / 1000000000);
    }
    public function subscribe(Closure $callback): string
    {
        return $this->listeners->subscribe($callback);
    }
    public function unsubscribe(string $id): void
    {
        $this->listeners->unsubscribe($id);
    }
    public function isRequested(): bool
    {
        return hrtime(true) >= $this->end || $this->listeners->isRequested();
    }
    public function throwIfRequested(): void
    {
        if (hrtime(true) >= $this->end) {
            throw new CancelledException(new TimeoutException('JetStream archive deadline expired'));
        }
        $this->listeners->throwIfRequested();
    }
    public function close(): void
    {
        EventLoop::cancel($this->timer);
    }
    public function __destruct()
    {
        $this->close();
    }
}
