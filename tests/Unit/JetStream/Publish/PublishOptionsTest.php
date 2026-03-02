<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Publish;

use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PublishOptionsTest extends TestCase
{
    public function testToHeadersAddsMsgId(): void
    {
        // Arrange
        $options = PublishOptions::create(msgId: 'msg-1');

        // Act
        $headers = $options->toHeaders();

        // Assert
        self::assertSame('msg-1', $headers['Nats-Msg-Id']);
    }

    public function testToHeadersAddsExpectedHeaders(): void
    {
        // Arrange
        $options = PublishOptions::create(
            expectedStream: 'ORDERS',
            expectedLastSeq: 12,
            expectedLastMsgId: 'msg-9',
            headers: ['X-Trace' => 'abc'],
        );

        // Act
        $headers = $options->toHeaders();

        // Assert
        self::assertSame('ORDERS', $headers['Nats-Expected-Stream']);
        self::assertSame('12', $headers['Nats-Expected-Last-Sequence']);
        self::assertSame('msg-9', $headers['Nats-Expected-Last-Msg-Id']);
        self::assertSame('abc', $headers['X-Trace']);
    }

    public function testToHeadersThrowsWhenCustomHeaderOverridesSystemHeader(): void
    {
        // Arrange
        $options = PublishOptions::create(
            msgId: 'sys-id',
            headers: ['nats-msg-id' => 'custom-id'],
        );

        // Assert
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must not override JetStream system header');

        // Act
        $options->toHeaders();
    }
}
