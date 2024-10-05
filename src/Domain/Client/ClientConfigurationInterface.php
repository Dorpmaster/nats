<?php

declare(strict_types=1);


namespace Dorpmaster\Nats\Domain\Client;

interface ClientConfigurationInterface
{
    public function getWaitForStatusTimeout(): float;
}
