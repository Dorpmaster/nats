<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Domain\Connection\ServerAddress;

use function Amp\delay;

final class NatsJetStreamClusterHarness
{
    /**
     * Test orchestration starts compose in Makefile. This method only asserts readiness.
     */
    public static function up(float $timeoutSeconds = 15.0): void
    {
        self::awaitClusterReady($timeoutSeconds);
    }

    /**
     * Test orchestration stops compose in Makefile. Kept for API symmetry.
     */
    public static function down(): void
    {
    }

    public static function stopNode(string $node): void
    {
        NatsClusterHarness::stopNode($node);
    }

    public static function startNode(string $node): void
    {
        NatsClusterHarness::startNode($node);
    }

    public static function restartNode(string $node): void
    {
        NatsClusterHarness::restartNode($node);
    }

    public static function waitNodeAcceptsInfo(string $node, float $timeoutSeconds = 10.0, int $pollStepMilliseconds = 50): void
    {
        NatsClusterHarness::waitNodeAcceptsInfo($node, $timeoutSeconds, $pollStepMilliseconds);
    }

    public static function waitNodeAcceptsInfoTls(string $node, float $timeoutSeconds = 10.0, int $pollStepMilliseconds = 50): void
    {
        NatsClusterHarness::waitNodeAcceptsInfoTls($node, $timeoutSeconds, $pollStepMilliseconds);
    }

    public static function waitNodeDown(string $node, float $timeoutSeconds = 10.0, int $pollStepMilliseconds = 50): void
    {
        NatsClusterHarness::waitNodeDown($node, $timeoutSeconds, $pollStepMilliseconds);
    }

    public static function awaitClusterReady(float $timeoutSeconds = 15.0): void
    {
        foreach (['n1', 'n2', 'n3'] as $node) {
            self::waitNodeReadyStably($node, $timeoutSeconds);
        }

        self::waitJetStreamMetaClusterReady('n1', $timeoutSeconds);
    }

    /** @return array{host: string, port: int} */
    public static function getNodeHostPort(string $node): array
    {
        return ['host' => $node, 'port' => 4222];
    }

    /** @return list<ServerAddress> */
    public static function getSeedServers(): array
    {
        $tlsEnabled = self::isTlsMode();

        return [
            new ServerAddress('n1', 4222, $tlsEnabled),
            new ServerAddress('n2', 4222, $tlsEnabled),
            new ServerAddress('n3', 4222, $tlsEnabled),
        ];
    }

    private static function isTlsMode(): bool
    {
        return (string) ($_SERVER['NATS_JS_CLUSTER_TLS'] ?? $_ENV['NATS_JS_CLUSTER_TLS'] ?? getenv('NATS_JS_CLUSTER_TLS') ?: '0') === '1';
    }

    private static function waitNodeReadyStably(string $node, float $timeoutSeconds): void
    {
        if (self::isTlsMode()) {
            self::waitNodeAcceptsInfoTls($node, $timeoutSeconds);
            self::waitNodeAcceptsInfoTls($node, $timeoutSeconds);

            return;
        }

        self::waitNodeAcceptsInfo($node, $timeoutSeconds);
        self::waitNodeAcceptsInfo($node, $timeoutSeconds);
    }

    private static function waitJetStreamMetaClusterReady(string $node, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $lastErr  = 'Unknown error';

        while (microtime(true) < $deadline) {
            $json = @file_get_contents(sprintf('http://%s:8222/jsz?accounts=true', $node));
            if (is_string($json)) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $metaCluster = $decoded['meta_cluster'] ?? null;
                    if (
                        is_array($metaCluster)
                        && is_string($metaCluster['leader'] ?? null)
                        && ($metaCluster['leader'] ?? '') !== ''
                        && (int) ($metaCluster['cluster_size'] ?? 0) >= 3
                    ) {
                        return;
                    }

                    $lastErr = 'meta_cluster is not ready yet';
                } else {
                    $lastErr = 'invalid JSON in /jsz response';
                }
            } else {
                $lastErr = 'cannot read /jsz endpoint';
            }

            delay(0.05);
        }

        throw new \RuntimeException(sprintf(
            'JetStream meta cluster is not ready on %s within %.1f seconds; last error: %s',
            $node,
            $timeoutSeconds,
            $lastErr,
        ));
    }
}
