<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Admin;

use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use PHPUnit\Framework\TestCase;

final class JetStreamAdminTest extends TestCase
{
    public function testCreateStreamSendsExpectedSubjectAndMapsResponse(): void
    {
        // Arrange
        $transport = $this->createMock(JetStreamControlPlaneTransportInterface::class);
        $transport->expects(self::once())
            ->method('request')
            ->with(
                'STREAM.CREATE.ORDERS',
                ['name' => 'ORDERS', 'subjects' => ['orders.*'], 'storage' => 'file'],
                null,
            )
            ->willReturn([
                'config' => [
                    'name' => 'ORDERS',
                    'subjects' => ['orders.*'],
                ],
            ]);

        $admin = new JetStreamAdmin($transport);

        // Act
        $info = $admin->createStream(new StreamConfig('ORDERS', ['orders.*'], 'file'));

        // Assert
        self::assertSame('ORDERS', $info->getName());
        self::assertSame(['orders.*'], $info->getSubjects());
    }

    public function testCreateOrUpdateConsumerSendsExpectedSubjectAndPayload(): void
    {
        // Arrange
        $transport = $this->createMock(JetStreamControlPlaneTransportInterface::class);
        $transport->expects(self::once())
            ->method('request')
            ->with(
                'CONSUMER.CREATE.ORDERS.C1',
                [
                    'stream_name' => 'ORDERS',
                    'config' => [
                        'durable_name' => 'C1',
                        'ack_policy' => 'explicit',
                        'ack_wait' => 500000000,
                        'max_ack_pending' => 100,
                        'filter_subject' => 'orders.created',
                    ],
                ],
                null,
            )
            ->willReturn([
                'config' => [
                    'durable_name' => 'C1',
                ],
            ]);

        $admin = new JetStreamAdmin($transport);

        // Act
        $consumer = $admin->createOrUpdateConsumer(
            'ORDERS',
            new ConsumerConfig('C1', ackWaitMs: 500, maxAckPending: 100, filterSubject: 'orders.created'),
        );

        // Assert
        self::assertSame('C1', $consumer->getDurableName());
    }

    public function testDeleteOperationsUseExpectedSubjects(): void
    {
        // Arrange
        $transport = $this->createMock(JetStreamControlPlaneTransportInterface::class);
        $transport->expects(self::exactly(2))
            ->method('request')
            ->withAnyParameters()
            ->willReturnCallback(static function (string $subjectSuffix, array $payload): array {
                self::assertSame([], $payload);
                self::assertContains($subjectSuffix, [
                    'STREAM.DELETE.ORDERS',
                    'CONSUMER.DELETE.ORDERS.C1',
                ]);

                return ['success' => true];
            });

        $admin = new JetStreamAdmin($transport);

        // Act
        $admin->deleteStream('ORDERS');
        $admin->deleteConsumer('ORDERS', 'C1');

        // Assert
        self::assertTrue(true);
    }
}
