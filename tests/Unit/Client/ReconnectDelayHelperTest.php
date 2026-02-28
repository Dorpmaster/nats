<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Dorpmaster\Nats\Client\ReconnectDelayHelper;
use PHPUnit\Framework\TestCase;

final class ReconnectDelayHelperTest extends TestCase
{
    public function testStaticCalculatorUsesExponentialBackoffWithoutJitter(): void
    {
        // Act
        $d1 = ReconnectDelayHelper::calculateReconnectDelayMs(1, 50, 1000, 2.0, 0.0);
        $d2 = ReconnectDelayHelper::calculateReconnectDelayMs(2, 50, 1000, 2.0, 0.0);
        $d3 = ReconnectDelayHelper::calculateReconnectDelayMs(3, 50, 1000, 2.0, 0.0);

        // Assert
        self::assertSame(50, $d1);
        self::assertSame(100, $d2);
        self::assertSame(200, $d3);
    }

    public function testStaticCalculatorRespectsMaxCap(): void
    {
        // Act
        $delay = ReconnectDelayHelper::calculateReconnectDelayMs(6, 50, 120, 2.0, 0.0);

        // Assert
        self::assertSame(120, $delay);
    }

    public function testInstanceCalculatorDelegatesToStaticLogic(): void
    {
        // Arrange
        $helper = new ReconnectDelayHelper();

        // Act
        $delay = $helper->calculateDelayMs(2, 50, 1000, 2.0, 0.0);

        // Assert
        self::assertSame(100, $delay);
    }
}
