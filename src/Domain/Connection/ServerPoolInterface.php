<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Connection;

interface ServerPoolInterface
{
    public function getCurrent(): ServerAddress|null;

    public function setCurrent(ServerAddress $server): void;

    /** @param list<ServerAddress> $servers */
    public function addServers(array $servers): void;

    /** @param list<ServerAddress> $servers */
    public function addDiscoveredServers(array $servers): void;

    public function markDead(ServerAddress $server, int $cooldownMs): void;

    public function nextServer(): ServerAddress|null;

    /** @return list<ServerAddress> */
    public function allServers(): array;
}
