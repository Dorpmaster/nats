<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Closure;

final readonly class ServerInformation
{
    public function __construct(
        public string|null $serverId = null,
        public string|null $version = null,
        public bool|null $jetStream = null,
        public int|null $apiLevel = null,
        public int|null $connectionId = null,
        private Closure|null $isCurrent = null,
    ) {
    }

    public function isCurrentConnection(): bool
    {
        return $this->isCurrent !== null && ($this->isCurrent)();
    }
}
