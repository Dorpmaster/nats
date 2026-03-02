<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use function Amp\delay;

final class NatsServerHarness
{
    private const string DEFAULT_DOCKER_SOCKET = '/var/run/docker.sock';

    public static function host(): string
    {
        return (string) ($_SERVER['NATS_HOST'] ?? $_ENV['NATS_HOST'] ?? getenv('NATS_HOST') ?: 'nats');
    }

    public static function port(): int
    {
        $value = $_SERVER['NATS_PORT'] ?? $_ENV['NATS_PORT'] ?? getenv('NATS_PORT') ?: '4222';

        return (int) $value;
    }

    public static function waitUntilReady(float $timeoutSeconds = 10.0, int $pollStepMilliseconds = 50): void
    {
        $host     = self::host();
        $port     = self::port();
        $deadline = microtime(true) + $timeoutSeconds;
        $lastErr  = 'Unknown error';

        while (microtime(true) < $deadline) {
            $errno  = 0;
            $errstr = '';
            $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            $lastErr = sprintf('[%d] %s', $errno, $errstr);
            delay($pollStepMilliseconds / 1000);
        }

        throw new \RuntimeException(sprintf(
            'NATS server is not ready at %s:%d within %.1f seconds; last error: %s',
            $host,
            $port,
            $timeoutSeconds,
            $lastErr,
        ));
    }

    public static function waitUntilDown(float $timeoutSeconds = 10.0, int $pollStepMilliseconds = 50): void
    {
        $host     = self::host();
        $port     = self::port();
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $errno  = 0;
            $errstr = '';
            $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);
            if (!is_resource($socket)) {
                return;
            }

            fclose($socket);
            delay($pollStepMilliseconds / 1000);
        }

        throw new \RuntimeException(sprintf(
            'NATS server is still reachable at %s:%d after %.1f seconds',
            $host,
            $port,
            $timeoutSeconds,
        ));
    }

    public static function restart(): void
    {
        self::dockerRequest('POST', sprintf('/containers/%s/restart?t=0', rawurlencode(self::containerName())), [204]);
    }

    public static function stop(): void
    {
        self::dockerRequest('POST', sprintf('/containers/%s/stop?t=0', rawurlencode(self::containerName())), [204, 304]);
    }

    public static function start(): void
    {
        self::dockerRequest('POST', sprintf('/containers/%s/start', rawurlencode(self::containerName())), [204, 304]);
    }

    public static function kill(): void
    {
        self::dockerRequest('POST', sprintf('/containers/%s/kill?signal=SIGKILL', rawurlencode(self::containerName())), [204, 409]);
    }

    public static function ensureUp(): void
    {
        self::start();
        self::waitUntilReady();
    }

    public static function ensureDown(): void
    {
        self::stop();
        self::waitUntilDown();
    }

    private static function containerName(): string
    {
        return (string) ($_SERVER['NATS_CONTAINER'] ?? $_ENV['NATS_CONTAINER'] ?? getenv('NATS_CONTAINER') ?: 'nats-it-nats-1');
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
