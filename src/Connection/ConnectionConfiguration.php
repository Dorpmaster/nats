<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Connection;

use Dorpmaster\Nats\Domain\Connection\ConnectionConfigurationInterface;
use Dorpmaster\Nats\Domain\Connection\TlsConfiguration;
use InvalidArgumentException;

final readonly class ConnectionConfiguration implements ConnectionConfigurationInterface
{
    public function __construct(
        private string $host,
        private int $port,
        private int $queueBufferSize = 1000,
        private TlsConfiguration|null $tls = null,
        private float $connectTimeout = 1.0,
    ) {
        if ($this->queueBufferSize < 0) {
            throw new InvalidArgumentException('Queue Buffer Size value must be a positive number or zero');
        }

        if ($this->connectTimeout <= 0) {
            throw new InvalidArgumentException('Connect timeout must be greater than zero');
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

    public function getConnectTimeout(): float
    {
        return $this->connectTimeout;
    }

    public function getTlsConfiguration(): TlsConfiguration
    {
        return $this->tls ?? TlsConfiguration::disabled();
    }
}
