<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Transport;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneHeadersRequestMessage;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamRawPubMessage;
use PHPUnit\Framework\TestCase;

final class JetStreamControlPlaneTransportPublishRequestTest extends TestCase
{
    public function testPublishRequestBuildsSubjectAndReplyTo(): void
    {
        // Arrange
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())
            ->method('publish')
            ->with(self::callback(function (JetStreamRawPubMessage $message): bool {
                self::assertSame('$JS.API.CONSUMER.MSG.NEXT.ORDERS.C1', $message->getSubject());
                self::assertSame('INBOX.1', $message->getReplyTo());
                self::assertSame('{"batch":1}', $message->getPayload());

                return true;
            }));

        $transport = new JetStreamControlPlaneTransport($client);

        // Act
        $transport->publishRequest('CONSUMER.MSG.NEXT.ORDERS.C1', ['batch' => 1], 'INBOX.1');

        // Assert
        self::assertTrue(true);
    }

    public function testPublishRequestUsesHpubWhenHeadersProvided(): void
    {
        // Arrange
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())
            ->method('publish')
            ->with(self::callback(function (JetStreamControlPlaneHeadersRequestMessage $message): bool {
                self::assertSame('$JS.API.CONSUMER.MSG.NEXT.ORDERS.C1', $message->getSubject());
                self::assertSame('trace-1', $message->getHeaders()->get('X-Trace'));

                return true;
            }));

        $transport = new JetStreamControlPlaneTransport($client);

        // Act
        $transport->publishRequest(
            'CONSUMER.MSG.NEXT.ORDERS.C1',
            ['batch' => 1],
            'INBOX.1',
            ['X-Trace' => 'trace-1'],
        );

        // Assert
        self::assertTrue(true);
    }
}
