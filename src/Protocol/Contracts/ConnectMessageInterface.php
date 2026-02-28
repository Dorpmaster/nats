<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Contracts;

use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;

interface ConnectMessageInterface
{
    public function getConnectInfo(): ConnectInfo;
}
