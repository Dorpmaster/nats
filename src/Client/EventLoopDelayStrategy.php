<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;

use function Amp\delay;

final class EventLoopDelayStrategy implements DelayStrategyInterface
{
    public function delay(int $milliseconds): void
    {
        delay(max(0, $milliseconds) / 1000);
    }
}
