<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

final class NatsClusterHarness
{
    private const string DEFAULT_DOCKER_SOCKET = '/var/run/docker.sock';

    public static function waitNodeReady(string $node, float $timeoutSeconds = 10.0, int $pollStepMilliseconds = 50): void
    {
        self::waitNodeAcceptsInfo($node, $timeoutSeconds, $pollStepMilliseconds);
    }

    public static function waitNodeAcceptsInfo(string $node, float $timeoutSeconds = 10.0, int $pollStepMilliseconds = 50): void
    {
        $host     = $node;
        $port     = 4222;
        $deadline = microtime(true) + $timeoutSeconds;
        $lastErr  = 'Unknown error';

        while (microtime(true) < $deadline) {
            $errno  = 0;
            $errstr = '';
            $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);
            if (is_resource($socket)) {
                stream_set_timeout($socket, 0, 250_000);
                $line = fgets($socket, 4096);
                fclose($socket);

                if (is_string($line) && str_starts_with($line, 'INFO ')) {
                    return;
                }

                $lastErr = sprintf('Connected, but INFO preface not received (line="%s")', trim((string) $line));
            } else {
                $lastErr = sprintf('[%d] %s', $errno, $errstr);
            }

            usleep($pollStepMilliseconds * 1000);
        }

        throw new \RuntimeException(sprintf(
            'Cluster node %s did not accept INFO at %s:%d within %.1f seconds; last error: %s',
            $node,
            $host,
            $port,
            $timeoutSeconds,
            $lastErr,
        ));
    }

    public static function waitNodeDown(string $node, float $timeoutSeconds = 10.0, int $pollStepMilliseconds = 50): void
    {
        $host     = $node;
        $port     = 4222;
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $errno  = 0;
            $errstr = '';
            $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);
            if (!is_resource($socket)) {
                return;
            }

            fclose($socket);
            usleep($pollStepMilliseconds * 1000);
        }

        throw new \RuntimeException(sprintf(
            'Cluster node %s is still reachable after %.1f seconds',
            $node,
            $timeoutSeconds,
        ));
    }

    public static function stopNode(string $node): void
    {
        self::dockerRequest('POST', sprintf('/containers/%s/stop?t=0', rawurlencode(self::containerName($node))), [204, 304]);
        self::waitNodeDown($node);
    }

    public static function killNode(string $node): void
    {
        self::dockerRequest('POST', sprintf('/containers/%s/kill', rawurlencode(self::containerName($node))), [204, 409]);
    }

    public static function startNode(string $node): void
    {
        self::dockerRequest('POST', sprintf('/containers/%s/start', rawurlencode(self::containerName($node))), [204, 304]);
        self::waitNodeAcceptsInfo($node);
    }

    public static function restartNode(string $node): void
    {
        self::dockerRequest('POST', sprintf('/containers/%s/restart?t=0', rawurlencode(self::containerName($node))), [204]);
        self::waitNodeAcceptsInfo($node);
    }

    private static function containerName(string $node): string
    {
        $map = [
            'n1' => (string) ($_SERVER['NATS_CLUSTER_N1_CONTAINER'] ?? $_ENV['NATS_CLUSTER_N1_CONTAINER'] ?? getenv('NATS_CLUSTER_N1_CONTAINER') ?: 'nats-cluster-it-n1-1'),
            'n2' => (string) ($_SERVER['NATS_CLUSTER_N2_CONTAINER'] ?? $_ENV['NATS_CLUSTER_N2_CONTAINER'] ?? getenv('NATS_CLUSTER_N2_CONTAINER') ?: 'nats-cluster-it-n2-1'),
            'n3' => (string) ($_SERVER['NATS_CLUSTER_N3_CONTAINER'] ?? $_ENV['NATS_CLUSTER_N3_CONTAINER'] ?? getenv('NATS_CLUSTER_N3_CONTAINER') ?: 'nats-cluster-it-n3-1'),
        ];

        if (!isset($map[$node])) {
            throw new \InvalidArgumentException(sprintf('Unknown cluster node "%s"', $node));
        }

        return $map[$node];
    }

    /** @param list<int> $expectedStatusCodes */
    private static function dockerRequest(string $method, string $path, array $expectedStatusCodes): void
    {
        $socketPath = (string) ($_SERVER['NATS_DOCKER_SOCKET'] ?? $_ENV['NATS_DOCKER_SOCKET'] ?? getenv('NATS_DOCKER_SOCKET') ?: self::DEFAULT_DOCKER_SOCKET);
        $socket     = @stream_socket_client(sprintf('unix://%s', $socketPath), $errno, $errstr, 1);
        if (!is_resource($socket)) {
            throw new \RuntimeException(sprintf(
                'Cannot connect to Docker socket "%s": [%d] %s',
                $socketPath,
                $errno,
                $errstr,
            ));
        }

        $request = sprintf(
            "%s %s HTTP/1.1\r\nHost: docker\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
            $method,
            $path,
        );
        fwrite($socket, $request);
        $statusLine = fgets($socket);
        fclose($socket);

        if ($statusLine === false || !preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            throw new \RuntimeException(sprintf('Unexpected Docker API response: "%s"', (string) $statusLine));
        }

        $statusCode = (int) $matches[1];
        if (!in_array($statusCode, $expectedStatusCodes, true)) {
            throw new \RuntimeException(sprintf(
                'Unexpected Docker API status code %d for "%s %s"',
                $statusCode,
                $method,
                $path,
            ));
        }
    }
}
