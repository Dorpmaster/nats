<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use Closure;

/** @internal Timer wakeups plus a synchronous queue-attempt deadline check. */
final readonly class DeadlineCancellation implements Cancellation
{
    private TimeoutCancellation $timer;
    public function __construct(private float $deadline)
    {
        $this->timer = new TimeoutCancellation(max(.000001, ($deadline - hrtime(true)) / 1000000000));
    }
    public function subscribe(Closure $callback): string
    {
        return $this->timer->subscribe($callback);
    }
    public function unsubscribe(string $id): void
    {
        $this->timer->unsubscribe($id);
    }
    public function isRequested(): bool
    {
        return hrtime(true) >= $this->deadline || $this->timer->isRequested();
    }
    public function throwIfRequested(): void
    {
        if (hrtime(true) >= $this->deadline) {
            throw new CancelledException(new TimeoutException('Scheduled publication deadline expired'));
        }
        $this->timer->throwIfRequested();
    }
}
