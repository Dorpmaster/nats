<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Connection;

use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Domain\Connection\ServerPoolInterface;
use Dorpmaster\Nats\Domain\Telemetry\TimeProviderInterface;

final class ServerPoolService implements ServerPoolInterface
{
    /** @var list<ServerAddress> */
    private array $servers = [];
    /** @var array<string, int> */
    private array $deadUntilByKey       = [];
    private int $cursor                 = -1;
    private ServerAddress|null $current = null;

    public function __construct(
        private readonly TimeProviderInterface $timeProvider,
    ) {
    }

    public function getCurrent(): ServerAddress|null
    {
        return $this->current;
    }

    public function setCurrent(ServerAddress $server): void
    {
        $this->addServers([$server]);
        $index = $this->findIndex($server);
        if ($index !== null) {
            $this->cursor  = $index;
            $this->current = $this->servers[$index];
        }
    }

    public function addServers(array $servers): void
    {
        foreach ($servers as $server) {
            if ($this->findIndex($server) !== null) {
                continue;
            }

            $this->servers[] = $server;
        }
    }

    public function addDiscoveredServers(array $servers): void
    {
        $this->addServers($servers);
    }

    public function markDead(ServerAddress $server, int $cooldownMs): void
    {
        $this->deadUntilByKey[$this->key($server)] = $this->timeProvider->nowMs() + max(0, $cooldownMs);
    }

    public function nextServer(): ServerAddress|null
    {
        $count = count($this->servers);
        if ($count === 0) {
            return null;
        }

        for ($attempt = 0; $attempt < $count; $attempt++) {
            $this->cursor = ($this->cursor + 1) % $count;
            $candidate    = $this->servers[$this->cursor];
            if ($this->isDead($candidate)) {
                continue;
            }

            $this->current = $candidate;

            return $candidate;
        }

        return null;
    }

    public function allServers(): array
    {
        return $this->servers;
    }

    private function isDead(ServerAddress $server): bool
    {
        $key   = $this->key($server);
        $until = $this->deadUntilByKey[$key] ?? null;
        if ($until === null) {
            return false;
        }

        if ($until <= $this->timeProvider->nowMs()) {
            unset($this->deadUntilByKey[$key]);

            return false;
        }

        return true;
    }

    private function findIndex(ServerAddress $server): int|null
    {
        foreach ($this->servers as $index => $item) {
            if ($item->equals($server)) {
                return $index;
            }
        }

        return null;
    }

    private function key(ServerAddress $server): string
    {
        return sprintf(
            '%s:%d:%d:%s',
            $server->getHost(),
            $server->getPort(),
            $server->isTlsEnabled() ? 1 : 0,
            $server->getServerName() ?? '',
        );
    }
}
