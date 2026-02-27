<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

final class NatsServerHarness
{
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
            usleep($pollStepMilliseconds * 1000);
        }

        throw new \RuntimeException(sprintf(
            'NATS server is not ready at %s:%d within %.1f seconds; last error: %s',
            $host,
            $port,
            $timeoutSeconds,
            $lastErr,
        ));
    }
}
