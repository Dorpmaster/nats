<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Contracts;

use Dorpmaster\Nats\Protocol\Metadata\ServerInfo;

interface InfoMessageInterface
{
    public function getServerInfo(): ServerInfo;
}
