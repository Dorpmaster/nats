<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;

final class ImmediateDelayStrategy implements DelayStrategyInterface
{
    public function delay(int $milliseconds): void
    {
    }
}
