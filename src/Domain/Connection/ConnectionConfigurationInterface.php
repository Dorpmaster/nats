<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Connection;

interface ConnectionConfigurationInterface
{
    public function getHost(): string;

    public function getPort(): int;
}
