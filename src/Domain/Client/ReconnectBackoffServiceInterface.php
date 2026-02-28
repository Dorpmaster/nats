<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Amp\Cancellation;

interface ReconnectBackoffServiceInterface
{
    /**
     * May throw CancelledException when reconnect backoff is cancelled by lifecycle actions
     * (for example close()/drain() or reconnect epoch replacement). This is normal control flow.
     *
     * @throws \Amp\CancelledException
     */
    public function wait(
        int $attempt,
        ClientConfigurationInterface $configuration,
        Cancellation|null $cancellation = null,
    ): void;
}
