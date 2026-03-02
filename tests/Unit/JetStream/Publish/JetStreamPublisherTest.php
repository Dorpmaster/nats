<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Publish;

use Amp\CancelledException;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;
use Dorpmaster\Nats\Protocol\HPubMessage;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class JetStreamPublisherTest extends TestCase
{
    public function testPublishBuildsHeadersAndParsesPubAck(): void
    {
        // Arrange
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())
            ->method('request')
            ->with(
                self::callback(function (HPubMessage $message): bool {
                    self::assertSame('orders.created', $message->getSubject());
                    self::assertSame("bin\0ary", $message->getPayload());
                    self::assertSame('m-1', $message->getHeaders()->get('Nats-Msg-Id'));
                    self::assertSame('trace', $message->getHeaders()->get('X-Request-Id'));

                    return true;
                }),
                self::callback(static function (float $timeout): bool {
                    self::assertGreaterThan(0.0, $timeout);
                    self::assertLessThanOrEqual(0.5, $timeout);

                    return true;
                }),
            )
            ->willReturn(new MsgMessage('reply', '1', '{"stream":"ORDERS","seq":10,"duplicate":false}'));

        $logger    = new RecordingLogger();
        $publisher = new JetStreamPublisher($client, $logger);

        // Act
        $ack = $publisher->publish(
            'orders.created',
            "bin\0ary",
            PublishOptions::create(msgId: 'm-1', headers: ['X-Request-Id' => 'trace']),
            500,
        );

        // Assert
        self::assertSame('ORDERS', $ack->getStream());
        self::assertSame(10, $ack->getSeq());
        self::assertFalse($ack->isDuplicate());
        $logger->assertHas(LogLevel::DEBUG, 'js.publish.start', static fn (array $context): bool => ($context['subject'] ?? null) === 'orders.created');
        $logger->assertHas(LogLevel::DEBUG, 'js.publish.ack', static fn (array $context): bool => ($context['stream'] ?? null) === 'ORDERS');
    }

    public function testPublishMapsJetStreamErrorResponseToException(): void
    {
        // Arrange
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())
            ->method('request')
            ->willReturn(new MsgMessage('reply', '1', '{"error":{"code":409,"description":"expected stream mismatch"}}'));

        $logger    = new RecordingLogger();
        $publisher = new JetStreamPublisher($client, $logger);

        // Assert
        self::expectException(JetStreamApiException::class);
        self::expectExceptionCode(409);
        self::expectExceptionMessage('expected stream mismatch');

        // Act
        $publisher->publish('orders.created', 'payload');
    }

    public function testPublishThrowsOnInvalidJsonAck(): void
    {
        // Arrange
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())
            ->method('request')
            ->willReturn(new MsgMessage('reply', '1', '{invalid'));

        $publisher = new JetStreamPublisher($client);

        // Assert
        self::expectException(JetStreamApiException::class);
        self::expectExceptionMessage('Failed to decode JetStream PubAck JSON');

        // Act
        $publisher->publish('orders.created', 'payload');
    }

    public function testPublishMapsTimeoutToJetStreamTimeoutException(): void
    {
        // Arrange
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())
            ->method('request')
            ->willThrowException(new CancelledException());

        $logger    = new RecordingLogger();
        $publisher = new JetStreamPublisher($client, $logger);

        // Assert
        self::expectException(JetStreamTimeoutException::class);

        // Act
        try {
            $publisher->publish('orders.created', 'payload', timeoutMs: 200);
        } finally {
            $logger->assertHas(LogLevel::WARNING, 'js.publish.timeout');
        }
    }
}
