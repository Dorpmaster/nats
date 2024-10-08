<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Connection;

use Dorpmaster\Nats\Domain\Connection\ConnectionConfigurationInterface;
use InvalidArgumentException;

final readonly class ConnectionConfiguration implements ConnectionConfigurationInterface
{
    public function __construct(
        private string $host,
        private int $port,
        private int $queueBufferSize = 1000,
    ) {
        if ($this->queueBufferSize < 0) {
            throw new InvalidArgumentException('Queue Buffer Size value must be a positive number or zero');
        }
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getQueueBufferSize(): int
    {
        return $this->queueBufferSize;
    }
}
