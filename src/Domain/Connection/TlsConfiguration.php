<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Connection;

final readonly class TlsConfiguration
{
    /**
     * @param list<string>|null $alpnProtocols
     */
    public function __construct(
        private bool $enabled = false,
        private bool $verifyPeer = true,
        private string|null $caFile = null,
        private string|null $caPath = null,
        private string|null $clientCertFile = null,
        private string|null $clientKeyFile = null,
        private string|null $clientKeyPassphrase = null,
        private string|null $serverName = null,
        private bool $allowSelfSigned = false,
        private string|null $minVersion = null,
        private array|null $alpnProtocols = null,
    ) {
    }

    public static function disabled(): self
    {
        return new self(enabled: false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isVerifyPeer(): bool
    {
        return $this->verifyPeer;
    }

    public function getCaFile(): string|null
    {
        return $this->caFile;
    }

    public function getCaPath(): string|null
    {
        return $this->caPath;
    }

    public function getClientCertFile(): string|null
    {
        return $this->clientCertFile;
    }

    public function getClientKeyFile(): string|null
    {
        return $this->clientKeyFile;
    }

    public function getClientKeyPassphrase(): string|null
    {
        return $this->clientKeyPassphrase;
    }

    public function getServerName(): string|null
    {
        return $this->serverName;
    }

    public function isAllowSelfSigned(): bool
    {
        return $this->allowSelfSigned;
    }

    public function getMinVersion(): string|null
    {
        return $this->minVersion;
    }

    /** @return list<string>|null */
    public function getAlpnProtocols(): array|null
    {
        return $this->alpnProtocols;
    }
}
