<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use PHPUnit\Framework\TestCase;

final class StreamConfigTest extends TestCase
{
    public function testToRequestPayloadContainsReplicasWhenProvided(): void
    {
        // Arrange
        $config = new StreamConfig(
            name: 'ORDERS',
            subjects: ['orders.*'],
            storage: 'file',
            replicas: 3,
        );

        // Act
        $payload = $config->toRequestPayload();

        // Assert
        self::assertSame('ORDERS', $payload['name']);
        self::assertSame(['orders.*'], $payload['subjects']);
        self::assertSame('file', $payload['storage']);
        self::assertSame(3, $payload['num_replicas']);
    }
}
