<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Transport;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneRequestMessage;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Protocol\MsgMessage;
use PHPUnit\Framework\TestCase;

final class JetStreamControlPlaneTransportTest extends TestCase
{
    public function testRequestBuildsApiSubjectAndSerializesPayload(): void
    {
        // Arrange
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())
            ->method('request')
            ->with(
                self::callback(function (JetStreamControlPlaneRequestMessage $message): bool {
                    self::assertSame('$JS.API.STREAM.INFO.ORDERS', $message->getSubject());
                    self::assertSame('{"foo":"bar"}', $message->getPayload());
                    self::assertStringStartsWith('JSREPLY', (string) $message->getReplyTo());

                    return true;
                }),
                0.75,
            )
            ->willReturn(new MsgMessage('reply', '1', '{"ok":true}'));

        $transport = new JetStreamControlPlaneTransport($client);

        // Act
        $response = $transport->request('STREAM.INFO.ORDERS', ['foo' => 'bar'], 750);

        // Assert
        self::assertSame(['ok' => true], $response);
    }

    public function testRequestMapsApiErrorToException(): void
    {
        // Arrange
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())
            ->method('request')
            ->willReturn(new MsgMessage('reply', '1', '{"error":{"code":404,"description":"stream not found"}}'));

        $transport = new JetStreamControlPlaneTransport($client);

        // Assert
        self::expectException(JetStreamApiException::class);
        self::expectExceptionCode(404);
        self::expectExceptionMessage('stream not found');

        // Act
        $transport->request('STREAM.INFO.ORDERS', []);
    }

    public function testRequestThrowsExceptionOnInvalidJsonResponse(): void
    {
        // Arrange
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())
            ->method('request')
            ->willReturn(new MsgMessage('reply', '1', '{invalid-json'));

        $transport = new JetStreamControlPlaneTransport($client);

        // Assert
        self::expectException(JetStreamApiException::class);
        self::expectExceptionMessage('Failed to decode JetStream API response JSON');

        // Act
        $transport->request('STREAM.INFO.ORDERS', []);
    }

    public function testRequestThrowsExceptionOnUnserializablePayload(): void
    {
        // Arrange
        $client    = $this->createStub(ClientInterface::class);
        $transport = new JetStreamControlPlaneTransport($client);

        // Assert
        self::expectException(JetStreamApiException::class);
        self::expectExceptionMessage('Failed to encode JetStream API request payload as JSON');

        // Act
        $transport->request('STREAM.INFO.ORDERS', ['resource' => fopen('php://memory', 'rb')]);
    }

    public function testRequestEncodesEmptyPayloadAsJsonObject(): void
    {
        // Arrange
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())
            ->method('request')
            ->with(
                self::callback(function (JetStreamControlPlaneRequestMessage $message): bool {
                    self::assertSame('{}', $message->getPayload());

                    return true;
                }),
            )
            ->willReturn(new MsgMessage('reply', '1', '{"ok":true}'));

        $transport = new JetStreamControlPlaneTransport($client);

        // Act
        $response = $transport->request('STREAM.INFO.ORDERS', []);

        // Assert
        self::assertSame(['ok' => true], $response);
    }
}
