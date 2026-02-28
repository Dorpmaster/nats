<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Connection;

use Dorpmaster\Nats\Connection\ConnectionConfiguration;
use Dorpmaster\Nats\Domain\Connection\TlsConfiguration;
use PHPUnit\Framework\TestCase;

final class ConnectionConfigurationTest extends TestCase
{
    public function testConfiguration(): void
    {
        $configuration = new ConnectionConfiguration(
            host: 'some.server.test',
            port: 4222,
        );

        self::assertSame('some.server.test', $configuration->getHost());
        self::assertSame(4222, $configuration->getPort());
        self::assertSame(1000, $configuration->getQueueBufferSize());
        self::assertSame(1.0, $configuration->getConnectTimeout());
        self::assertEquals(TlsConfiguration::disabled(), $configuration->getTlsConfiguration());
    }

    public function testThrowsOnInvalidConnectTimeout(): void
    {
        // Arrange
        $this->expectException(\InvalidArgumentException::class);

        // Act
        new ConnectionConfiguration(
            host: 'some.server.test',
            port: 4222,
            connectTimeout: 0.0,
        );

        // Assert is covered by expected exception.
    }
}
