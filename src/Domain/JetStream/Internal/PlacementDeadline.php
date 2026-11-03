<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Amp\Cancellation;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use InvalidArgumentException;

/** @internal One monotonic budget for admission, command and cleanup. */
final readonly class PlacementDeadline
{
    private int $deadline;
    public Cancellation $cancellation;
    public function __construct(mixed $timeoutMs, Cancellation|null $cancellation)
    {
        $now     = hrtime(true);
        $timeout = $timeoutMs ?? 2000;
        if (!is_int($timeout) || $timeout <= 0 || $timeout > intdiv(PHP_INT_MAX - $now, 1000000)) {
            throw new InvalidArgumentException('Invalid timeoutMs: expected a positive bounded duration');
        }
        $this->deadline     = $now + $timeout * 1000000;
        $this->cancellation = new \Amp\CompositeCancellation($cancellation ?? new \Amp\NullCancellation(), new DeadlineCancellation($this->deadline, 'JetStream placement deadline expired'));
    }
    public function remaining(): int
    {
        $remaining = intdiv($this->deadline - hrtime(true), 1000000);
        if ($remaining <= 0) {
            throw new JetStreamTimeoutException('JetStream placement deadline expired');
        }
        $this->cancellation->throwIfRequested();
        return $remaining;
    }
}
