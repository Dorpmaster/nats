<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\Cancellation;
use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;

final class ImmediateDelayStrategy implements DelayStrategyInterface
{
    public function delay(int $milliseconds, Cancellation|null $cancellation = null): void
    {
        $cancellation?->throwIfRequested();
    }
}
