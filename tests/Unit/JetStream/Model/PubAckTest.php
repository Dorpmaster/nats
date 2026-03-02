<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Model\PubAck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PubAckTest extends TestCase
{
    public function testFromArrayParsesRequiredFields(): void
    {
        // Arrange
        $payload = ['stream' => 'ORDERS', 'seq' => 1, 'duplicate' => true];

        // Act
        $ack = PubAck::fromArray($payload);

        // Assert
        self::assertSame('ORDERS', $ack->getStream());
        self::assertSame(1, $ack->getSeq());
        self::assertTrue($ack->isDuplicate());
    }

    public function testFromArrayDefaultsDuplicateToFalse(): void
    {
        // Arrange
        $payload = ['stream' => 'ORDERS', 'seq' => 2];

        // Act
        $ack = PubAck::fromArray($payload);

        // Assert
        self::assertFalse($ack->isDuplicate());
    }

    public function testFromArrayRequiresPositiveSequence(): void
    {
        // Arrange
        $payload = ['stream' => 'ORDERS', 'seq' => 0];

        // Assert
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('greater than zero');

        // Act
        PubAck::fromArray($payload);
    }
}
