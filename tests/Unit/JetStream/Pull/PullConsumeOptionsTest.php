<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Pull;

use Dorpmaster\Nats\Domain\JetStream\Pull\PullConsumeOptions;
use PHPUnit\Framework\TestCase;

final class PullConsumeOptionsTest extends TestCase
{
    public function testValidOptionsAreCreated(): void
    {
        // Arrange
        // Act
        $options = new PullConsumeOptions(batch: 10, expiresMs: 1_000);

        // Assert
        self::assertSame(10, $options->batch);
        self::assertSame(1_000, $options->expiresMs);
    }

    public function testInvalidBatchThrows(): void
    {
        // Arrange
        self::expectException(\InvalidArgumentException::class);

        // Act
        new PullConsumeOptions(batch: 0, expiresMs: 1000);
    }

    public function testInvalidMaxInFlightBytesThrows(): void
    {
        // Arrange
        self::expectException(\InvalidArgumentException::class);

        // Act
        new PullConsumeOptions(batch: 1, expiresMs: 1000, maxInFlightBytes: 0);
    }
}
