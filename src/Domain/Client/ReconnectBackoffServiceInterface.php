<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

interface ReconnectBackoffServiceInterface
{
    /**
     * @throws \Amp\CancelledException
     */
    public function wait(int $attempt, ClientConfigurationInterface $configuration): void;
}
