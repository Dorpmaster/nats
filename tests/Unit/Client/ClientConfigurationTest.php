<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Dorpmaster\Nats\Client\ClientConfiguration;
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
        self::assertSame([], $configuration->getReconnectServers());
    }

    public function testConfiguration(): void
    {
        // Arrange
        $configuration = new ClientConfiguration(
            waitForStatusTimeout: 30,
            reconnectEnabled: true,
            maxReconnectAttempts: null,
            reconnectBackoffInitialMs: 100,
            reconnectBackoffMaxMs: 3000,
            reconnectBackoffMultiplier: 1.5,
            reconnectJitterFraction: 0.0,
            reconnectServers: ['nats://127.0.0.1:4222'],
        );

        // Assert
        self::assertSame(30.0, $configuration->getWaitForStatusTimeout());
        self::assertTrue($configuration->isReconnectEnabled());
        self::assertNull($configuration->getMaxReconnectAttempts());
        self::assertSame(100, $configuration->getReconnectBackoffInitialMs());
        self::assertSame(3000, $configuration->getReconnectBackoffMaxMs());
        self::assertSame(1.5, $configuration->getReconnectBackoffMultiplier());
        self::assertSame(0.0, $configuration->getReconnectJitterFraction());
        self::assertSame(['nats://127.0.0.1:4222'], $configuration->getReconnectServers());
    }
}
