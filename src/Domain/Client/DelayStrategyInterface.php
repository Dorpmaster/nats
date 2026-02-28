<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

interface DelayStrategyInterface
{
    /**
     * @throws \Amp\CancelledException
     */
    public function delay(int $milliseconds): void;
}
