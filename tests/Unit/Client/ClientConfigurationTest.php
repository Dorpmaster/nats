<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Dorpmaster\Nats\Client\ClientConfiguration;
use PHPUnit\Framework\TestCase;

final class ClientConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $configuration = new ClientConfiguration();

        self::assertSame(10.0, $configuration->getWaitForStatusTimeout());
    }

    public function testConfiguration(): void
    {
        $configuration = new ClientConfiguration(
            waitForStatusTimeout: 30,
        );

        self::assertSame(30.0, $configuration->getWaitForStatusTimeout());
    }
}
