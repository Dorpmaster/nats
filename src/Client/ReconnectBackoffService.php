<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Dorpmaster\Nats\Domain\Client\ClientConfigurationInterface;
use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;
use Dorpmaster\Nats\Domain\Client\ReconnectBackoffServiceInterface;
use Dorpmaster\Nats\Domain\Client\ReconnectDelayHelperInterface;

final readonly class ReconnectBackoffService implements ReconnectBackoffServiceInterface
{
    public function __construct(
        private DelayStrategyInterface $delayStrategy,
        private ReconnectDelayHelperInterface $reconnectDelayHelper,
    ) {
    }

    /**
     * @throws \Amp\CancelledException
     */
    public function wait(int $attempt, ClientConfigurationInterface $configuration): void
    {
        $delayMs = $this->reconnectDelayHelper->calculateDelayMs(
            $attempt,
            $configuration->getReconnectBackoffInitialMs(),
            $configuration->getReconnectBackoffMaxMs(),
            $configuration->getReconnectBackoffMultiplier(),
            $configuration->getReconnectJitterFraction(),
        );

        $this->delayStrategy->delay($delayMs);
    }
}
