<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Contracts;

interface UnSubMessageInterface
{
    public function getMaxMessages(): int|null;

    public function getSid(): string;
}
