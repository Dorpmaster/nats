<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client\Cluster;

use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Protocol\Contracts\InfoMessageInterface;

final class InfoConnectUrlsExtractor
{
    /**
     * @return list<ServerAddress>
     */
    public function extract(
        InfoMessageInterface $infoMessage,
        bool $tlsEnabled = false,
    ): array {
        $urls    = $infoMessage->getServerInfo()->connect_urls ?? [];
        $servers = [];

        foreach ($urls as $url) {
            $server = $this->parseAddress($url, $tlsEnabled);
            if ($server === null) {
                continue;
            }

            if ($this->contains($servers, $server)) {
                continue;
            }

            $servers[] = $server;
        }

        return $servers;
    }

    private function parseAddress(string $url, bool $tlsEnabled): ServerAddress|null
    {
        $raw   = str_contains($url, '://') ? $url : 'nats://' . $url;
        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return null;
        }

        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;
        if (!is_string($host) || !is_int($port) || $port <= 0) {
            return null;
        }

        return new ServerAddress($host, $port, $tlsEnabled);
    }

    /**
     * @param list<ServerAddress> $servers
     */
    private function contains(array $servers, ServerAddress $target): bool
    {
        foreach ($servers as $server) {
            if ($server->equals($target)) {
                return true;
            }
        }

        return false;
    }
}
