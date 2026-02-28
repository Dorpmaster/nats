<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Amp\Cancellation;

interface DelayStrategyInterface
{
    /**
     * @throws \Amp\CancelledException
     */
    public function delay(int $milliseconds, Cancellation|null $cancellation = null): void;
}
