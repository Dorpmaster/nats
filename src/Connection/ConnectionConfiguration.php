<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Connection;

use Dorpmaster\Nats\Domain\Connection\ConnectionConfigurationInterface;

final readonly class ConnectionConfiguration implements ConnectionConfigurationInterface
{
    public function __construct(
        private string $host,
        private int    $port,
    )
    {
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }
}
