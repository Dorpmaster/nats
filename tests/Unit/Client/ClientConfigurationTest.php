<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\WriteBufferPolicy;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Tests\Support\FakeTimeProvider;
use Dorpmaster\Nats\Tests\Support\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;

final class ClientConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        // Arrange
        $configuration = new ClientConfiguration();

        // Assert
        self::assertSame(10.0, $configuration->getWaitForStatusTimeout());
        self::assertFalse($configuration->isReconnectEnabled());
        self::assertSame(10, $configuration->getMaxReconnectAttempts());
        self::assertSame(50, $configuration->getReconnectBackoffInitialMs());
        self::assertSame(1000, $configuration->getReconnectBackoffMaxMs());
        self::assertSame(2.0, $configuration->getReconnectBackoffMultiplier());
        self::assertSame(0.2, $configuration->getReconnectJitterFraction());
        self::assertSame([], $configuration->getServers());
        self::assertSame(2_000, $configuration->getDeadServerCooldownMs());
        self::assertSame(10_000, $configuration->getMaxWriteBufferMessages());
        self::assertSame(5_000_000, $configuration->getMaxWriteBufferBytes());
        self::assertSame(64, $configuration->getMaxInboundDispatchConcurrency());
        self::assertSame(1_024, $configuration->getMaxPendingInboundDispatch());
        self::assertSame(WriteBufferPolicy::ERROR, $configuration->getWriteBufferPolicy());
        self::assertFalse($configuration->isBufferWhileReconnecting());
        self::assertFalse($configuration->isPingEnabled());
        self::assertSame(30_000, $configuration->getPingIntervalMs());
        self::assertSame(2_000, $configuration->getPingTimeoutMs());
        self::assertTrue($configuration->isPingReconnectOnTimeout());
        self::assertNotNull($configuration->getMetricsCollector());
        self::assertNotNull($configuration->getTimeProvider());
    }

    public function testConfiguration(): void
    {
        // Arrange
        $configuration = new ClientConfiguration(
            metricsCollector: new RecordingMetricsCollector(),
            timeProvider: new FakeTimeProvider(),
            waitForStatusTimeout: 30,
            reconnectEnabled: true,
            maxReconnectAttempts: null,
            reconnectBackoffInitialMs: 100,
            reconnectBackoffMaxMs: 3000,
            reconnectBackoffMultiplier: 1.5,
            reconnectJitterFraction: 0.0,
            servers: [new ServerAddress('127.0.0.1', 4222)],
            deadServerCooldownMs: 10_000,
            maxWriteBufferMessages: 50,
            maxWriteBufferBytes: 123_456,
            maxInboundDispatchConcurrency: 7,
            maxPendingInboundDispatch: 77,
            writeBufferPolicy: WriteBufferPolicy::DROP_NEW,
            bufferWhileReconnecting: true,
            pingEnabled: false,
            pingIntervalMs: 5_000,
            pingTimeoutMs: 500,
            pingReconnectOnTimeout: false,
        );

        // Assert
        self::assertSame(30.0, $configuration->getWaitForStatusTimeout());
        self::assertTrue($configuration->isReconnectEnabled());
        self::assertNull($configuration->getMaxReconnectAttempts());
        self::assertSame(100, $configuration->getReconnectBackoffInitialMs());
        self::assertSame(3000, $configuration->getReconnectBackoffMaxMs());
        self::assertSame(1.5, $configuration->getReconnectBackoffMultiplier());
        self::assertSame(0.0, $configuration->getReconnectJitterFraction());
        self::assertEquals([new ServerAddress('127.0.0.1', 4222)], $configuration->getServers());
        self::assertSame(10_000, $configuration->getDeadServerCooldownMs());
        self::assertSame(50, $configuration->getMaxWriteBufferMessages());
        self::assertSame(123_456, $configuration->getMaxWriteBufferBytes());
        self::assertSame(7, $configuration->getMaxInboundDispatchConcurrency());
        self::assertSame(77, $configuration->getMaxPendingInboundDispatch());
        self::assertSame(WriteBufferPolicy::DROP_NEW, $configuration->getWriteBufferPolicy());
        self::assertTrue($configuration->isBufferWhileReconnecting());
        self::assertFalse($configuration->isPingEnabled());
        self::assertSame(5_000, $configuration->getPingIntervalMs());
        self::assertSame(500, $configuration->getPingTimeoutMs());
        self::assertFalse($configuration->isPingReconnectOnTimeout());
    }

    public function testRejectsNonPositiveInboundDispatchConcurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxInboundDispatchConcurrency must be greater than 0');

        new ClientConfiguration(maxInboundDispatchConcurrency: 0);
    }

    public function testRejectsNonPositivePendingInboundDispatchLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxPendingInboundDispatch must be greater than 0');

        new ClientConfiguration(maxPendingInboundDispatch: 0);
    }
}
