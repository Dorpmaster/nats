<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;

final class ManualDelayStrategy implements DelayStrategyInterface
{
    /** @var list<DeferredFuture<void>> */
    private array $pending = [];
    /** @var list<int> */
    private array $delays = [];

    public function delay(int $milliseconds, Cancellation|null $cancellation = null): void
    {
        $deferred        = new DeferredFuture();
        $this->pending[] = $deferred;
        $this->delays[]  = $milliseconds;

        try {
            $deferred->getFuture()->await($cancellation);
        } finally {
            foreach ($this->pending as $index => $item) {
                if ($item === $deferred) {
                    unset($this->pending[$index]);
                    $this->pending = array_values($this->pending);
                    break;
                }
            }
        }
    }

    public function releaseNext(): void
    {
        $deferred = array_shift($this->pending);
        if ($deferred === null) {
            return;
        }

        if (!$deferred->isComplete()) {
            $deferred->complete();
        }
    }

    public function releaseAll(): void
    {
        while (isset($this->pending[0])) {
            $this->releaseNext();
        }
    }

    /** @return list<int> */
    public function delays(): array
    {
        return $this->delays;
    }
}
