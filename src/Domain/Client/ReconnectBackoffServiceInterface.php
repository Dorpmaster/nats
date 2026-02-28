<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Amp\Cancellation;

interface ReconnectBackoffServiceInterface
{
    /**
     * @throws \Amp\CancelledException
     */
    public function wait(
        int $attempt,
        ClientConfigurationInterface $configuration,
        Cancellation|null $cancellation = null,
    ): void;
}
