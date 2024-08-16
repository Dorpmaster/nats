<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Connection;

use Dorpmaster\Nats\Connection\ConnectionConfiguration;
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
    }
}
