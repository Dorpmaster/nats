<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Dorpmaster\Nats\Client\SubscriptionIdHelper;
use PHPUnit\Framework\TestCase;

final class SubscriptionIdHelperTest extends TestCase
{
    public function testStaticGeneratorCreatesValidId(): void
    {
        // Arrange + Act
        $id = SubscriptionIdHelper::generateSubscriptionId();

        // Assert
        self::assertNotSame('', $id);
        self::assertStringNotContainsString('.', $id);
    }

    public function testInstanceGeneratorDelegatesToStaticGenerator(): void
    {
        // Arrange
        $helper = new SubscriptionIdHelper();

        // Act
        $id = $helper->generateId();

        // Assert
        self::assertNotSame('', $id);
        self::assertStringNotContainsString('.', $id);
    }
}
