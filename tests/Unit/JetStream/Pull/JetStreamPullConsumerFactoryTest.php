<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Pull;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcknowledgerInterface;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumerFactory;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumerInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use PHPUnit\Framework\TestCase;

final class JetStreamPullConsumerFactoryTest extends TestCase
{
    public function testCreateReturnsPullConsumer(): void
    {
        // Arrange
        $transport = $this->createStub(JetStreamControlPlaneTransportInterface::class);
        $transport->method('getClient')->willReturn($this->createStub(ClientInterface::class));
        $factory = new JetStreamPullConsumerFactory($transport);

        // Act
        $consumer = $factory->create('ORDERS', 'C1');

        // Assert
        self::assertInstanceOf(JetStreamPullConsumerInterface::class, $consumer);
    }

    public function testCreateWithCustomAcknowledger(): void
    {
        // Arrange
        $transport = $this->createStub(JetStreamControlPlaneTransportInterface::class);
        $transport->method('getClient')->willReturn($this->createStub(ClientInterface::class));
        $acknowledger = $this->createStub(JetStreamMessageAcknowledgerInterface::class);
        $factory      = new JetStreamPullConsumerFactory($transport, $acknowledger);

        // Act
        $consumer = $factory->create('ORDERS', 'C1');

        // Assert
        self::assertInstanceOf(JetStreamPullConsumerInterface::class, $consumer);
    }
}
