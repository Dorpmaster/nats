<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

interface ServerInformationProviderInterface
{
    public function getServerInformation(): ServerInformation;
}
