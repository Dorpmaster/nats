<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Connection;

use Dorpmaster\Nats\Connection\ServerPoolService;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Tests\Support\FakeTimeProvider;
use PHPUnit\Framework\TestCase;

final class ServerPoolServiceTest extends TestCase
{
    public function testRoundRobin(): void
    {
        // Arrange
        $pool = new ServerPoolService(new FakeTimeProvider());
        $a    = new ServerAddress('a', 4222);
        $b    = new ServerAddress('b', 4222);
        $c    = new ServerAddress('c', 4222);
        $pool->addServers([$a, $b, $c]);

        // Act
        $order = [
            $pool->nextServer(),
            $pool->nextServer(),
            $pool->nextServer(),
            $pool->nextServer(),
        ];

        // Assert
        self::assertEquals([$a, $b, $c, $a], $order);
    }

    public function testDedupe(): void
    {
        // Arrange
        $pool = new ServerPoolService(new FakeTimeProvider());
        $a    = new ServerAddress('a', 4222);
        $b    = new ServerAddress('b', 4222);

        // Act
        $pool->addServers([$a, $b]);
        $pool->addServers([$b]);

        // Assert
        self::assertCount(2, $pool->allServers());
    }

    public function testMarkDeadSkipsServerUntilCooldownExpires(): void
    {
        // Arrange
        $clock = new FakeTimeProvider();
        $pool  = new ServerPoolService($clock);
        $a     = new ServerAddress('a', 4222);
        $b     = new ServerAddress('b', 4222);
        $pool->addServers([$a, $b]);
        $pool->markDead($a, 5000);

        // Act
        $first = $pool->nextServer();
        $clock->advanceMs(5001);
        $second = $pool->nextServer();

        // Assert
        self::assertEquals($b, $first);
        self::assertEquals($a, $second);
    }

    public function testAllDeadReturnsNull(): void
    {
        // Arrange
        $pool = new ServerPoolService(new FakeTimeProvider());
        $a    = new ServerAddress('a', 4222);
        $b    = new ServerAddress('b', 4222);
        $pool->addServers([$a, $b]);
        $pool->markDead($a, 5000);
        $pool->markDead($b, 5000);

        // Act
        $server = $pool->nextServer();

        // Assert
        self::assertNull($server);
    }

    public function testDiscoveredServersParticipateInRoundRobin(): void
    {
        // Arrange
        $pool = new ServerPoolService(new FakeTimeProvider());
        $a    = new ServerAddress('a', 4222);
        $b    = new ServerAddress('b', 4222);
        $c    = new ServerAddress('c', 4222);
        $pool->addServers([$a]);
        $pool->addDiscoveredServers([$b, $c]);

        // Act
        $order = [
            $pool->nextServer(),
            $pool->nextServer(),
            $pool->nextServer(),
        ];

        // Assert
        self::assertEquals([$a, $b, $c], $order);
    }
}
