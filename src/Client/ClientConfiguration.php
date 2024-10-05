<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Dorpmaster\Nats\Domain\Client\ClientConfigurationInterface;

final readonly class ClientConfiguration implements ClientConfigurationInterface
{
    public function __construct(
        private float $waitForStatusTimeout = 10,
    )
    {
    }

    public function getWaitForStatusTimeout(): float
    {
        return $this->waitForStatusTimeout;
    }
}
