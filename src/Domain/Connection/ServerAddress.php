<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Connection;

final readonly class ServerAddress
{
    public function __construct(
        private string $host,
        private int $port,
        private bool $tlsEnabled = false,
        private string|null $serverName = null,
    ) {
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function isTlsEnabled(): bool
    {
        return $this->tlsEnabled;
    }

    public function getServerName(): string|null
    {
        return $this->serverName;
    }

    public function toUri(): string
    {
        $scheme = $this->tlsEnabled ? 'tls' : 'tcp';

        return sprintf('%s://%s:%d', $scheme, $this->host, $this->port);
    }

    public function equals(ServerAddress $other): bool
    {
        return $this->host === $other->host
            && $this->port === $other->port
            && $this->tlsEnabled === $other->tlsEnabled
            && $this->serverName === $other->serverName;
    }
}
