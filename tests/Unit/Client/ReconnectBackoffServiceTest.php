<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Client\ReconnectBackoffService;
use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;
use Dorpmaster\Nats\Domain\Client\ReconnectDelayHelperInterface;
use PHPUnit\Framework\TestCase;

final class ReconnectBackoffServiceTest extends TestCase
{
    public function testWaitUsesDelayHelperAndDelayStrategy(): void
    {
        // Arrange
        $helper = self::createMock(ReconnectDelayHelperInterface::class);
        $helper->expects(self::once())
            ->method('calculateDelayMs')
            ->with(3, 50, 1000, 2.0, 0.0)
            ->willReturn(250);

        $delay = self::createMock(DelayStrategyInterface::class);
        $delay->expects(self::once())
            ->method('delay')
            ->with(250);

        $service = new ReconnectBackoffService($delay, $helper);
        $config  = new ClientConfiguration(
            reconnectBackoffInitialMs: 50,
            reconnectBackoffMaxMs: 1000,
            reconnectBackoffMultiplier: 2.0,
            reconnectJitterFraction: 0.0,
        );

        // Act
        $service->wait(3, $config);
    }
}
