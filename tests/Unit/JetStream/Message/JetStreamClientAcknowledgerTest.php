<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Message;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamClientAcknowledger;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamRawPubMessage;
use PHPUnit\Framework\TestCase;

final class JetStreamClientAcknowledgerTest extends TestCase
{
    public function testAcknowledgePublishesRawAckMessage(): void
    {
        // Arrange
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())
            ->method('publish')
            ->with(self::callback(static function (JetStreamRawPubMessage $message): bool {
                self::assertSame('$JS.ACK.ORDERS.C1.1', $message->getSubject());
                self::assertSame('+ACK', $message->getPayload());

                return true;
            }));

        $acknowledger = new JetStreamClientAcknowledger($client);

        // Act
        $acknowledger->acknowledge('$JS.ACK.ORDERS.C1.1', '+ACK');

        // Assert
        self::assertTrue(true);
    }
}
