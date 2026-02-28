<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\Cancellation;
use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;

final class RecordingDelayStrategy implements DelayStrategyInterface
{
    /** @var list<int> */
    private array $delays = [];

    public function delay(int $milliseconds, Cancellation|null $cancellation = null): void
    {
        $cancellation?->throwIfRequested();
        $this->delays[] = $milliseconds;
    }

    /** @return list<int> */
    public function delays(): array
    {
        return $this->delays;
    }
}
