<?php

declare(strict_types=1);


namespace Dorpmaster\Nats\Domain\Client;

interface ClientInterface
{
    public function connect(): void;
}
