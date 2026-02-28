<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Connection;

interface ServerSelectableConnectionInterface extends ConnectionInterface
{
    public function useServer(ServerAddress $server): void;
}
