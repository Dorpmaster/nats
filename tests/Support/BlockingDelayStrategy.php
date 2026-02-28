<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;

final class BlockingDelayStrategy implements DelayStrategyInterface
{
    /** @var list<int> */
    private array $delays = [];

    public function delay(int $milliseconds, Cancellation|null $cancellation = null): void
    {
        $this->delays[] = $milliseconds;

        // Keep reconnect waiting until cancellation is requested.
        (new DeferredFuture())->getFuture()->await($cancellation);
    }

    /** @return list<int> */
    public function delays(): array
    {
        return $this->delays;
    }
}
