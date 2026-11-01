<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdminInterface;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerInfo;
use Dorpmaster\Nats\Domain\JetStream\Model\PubAck;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisherInterface;
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\MsgMessage;
use PHPUnit\Framework\TestCase;

final class JetStreamLegacyCompatibilityTest extends TestCase
{
    public function testConcreteLegacyClientInterface(): void
    {
        $inner = self::createMock(ClientInterface::class);
        $inner->expects(self::once())->method('request')->willReturn(new MsgMessage('reply', '1', '{}'));
        $legacy = new class ($inner) implements ClientInterface {
            public function __construct(private ClientInterface $inner)
            {
            }

            public function connect(): void
            {
                $this->inner->connect();
            }

            public function disconnect(): void
            {
                $this->inner->disconnect();
            }

            public function waitForTermination(): void
            {
                $this->inner->waitForTermination();
            }

            public function subscribe(string $subject, \Closure $closure, string|null $queueGroup = null): string
            {
                return $this->inner->subscribe($subject, $closure, $queueGroup);
            }

            public function unsubscribe(string $sid): void
            {
                $this->inner->unsubscribe($sid);
            }

            public function publish(PubMessageInterface|HPubMessageInterface $message): void
            {
                $this->inner->publish($message);
            }

            public function request(
                PubMessageInterface|HPubMessageInterface $message,
                float $timeout = 30
            ): MsgMessageInterface|HMsgMessageInterface {
                return $this->inner->request($message, $timeout);
            }
        };
        self::assertSame([], (new JetStreamControlPlaneTransport($legacy))->request('INFO', []));
    }

    public function testConcreteLegacyJetStreamControlPlaneTransportInterface(): void
    {
        $inner = self::createMock(JetStreamControlPlaneTransportInterface::class);
        $inner->expects(self::once())->method('request')->willReturn(['config' => ['name' => 'S', 'subjects' => ['s']]]);
        $legacy = new class ($inner) implements JetStreamControlPlaneTransportInterface {
            public function __construct(private JetStreamControlPlaneTransportInterface $inner)
            {
            }

            public function getClient(): ClientInterface
            {
                return $this->inner->getClient();
            }

            public function getSubscriptionIdHelper(): SubscriptionIdHelperInterface
            {
                return $this->inner->getSubscriptionIdHelper();
            }

            public function request(string $apiSubjectSuffix, array $payload, int|null $timeoutMs = null): array
            {
                return $this->inner->request($apiSubjectSuffix, $payload, $timeoutMs);
            }

            public function publishRequest(
                string $apiSubjectSuffix,
                array $payload,
                string $replyTo,
                array $headers = [],
            ): void {
                $this->inner->publishRequest($apiSubjectSuffix, $payload, $replyTo, $headers);
            }
        };
        self::assertSame('S', (new JetStreamAdmin($legacy))->getStreamInfo('S')->getName());
    }

    public function testConcreteLegacyJetStreamAdminInterface(): void
    {
        $inner = self::createMock(JetStreamAdminInterface::class);
        $inner->expects(self::once())->method('getStreamInfo')->willReturn(new StreamInfo('S', ['s']));
        $legacy = new class ($inner) implements JetStreamAdminInterface {
            public function __construct(private JetStreamAdminInterface $inner)
            {
            }

            public function createStream(StreamConfig $config): StreamInfo
            {
                return $this->inner->createStream($config);
            }

            public function getStreamInfo(string $stream): StreamInfo
            {
                return $this->inner->getStreamInfo($stream);
            }

            public function deleteStream(string $stream): void
            {
                $this->inner->deleteStream($stream);
            }

            public function createOrUpdateConsumer(string $stream, ConsumerConfig $config): ConsumerInfo
            {
                return $this->inner->createOrUpdateConsumer($stream, $config);
            }

            public function getConsumerInfo(string $stream, string $consumer): ConsumerInfo
            {
                return $this->inner->getConsumerInfo($stream, $consumer);
            }

            public function deleteConsumer(string $stream, string $consumer): void
            {
                $this->inner->deleteConsumer($stream, $consumer);
            }
        };
        self::assertSame(['s'], $legacy->getStreamInfo('S')->getSubjects());
    }

    public function testConcreteLegacyJetStreamPublisherInterface(): void
    {
        $inner = self::createMock(JetStreamPublisherInterface::class);
        $inner->expects(self::once())->method('publish')->willReturn(new PubAck('S', 1));
        $legacy = new class ($inner) implements JetStreamPublisherInterface {
            public function __construct(private JetStreamPublisherInterface $inner)
            {
            }

            public function publish(
                string $subject,
                string $payload,
                PublishOptions|null $options = null,
                int|null $timeoutMs = null,
            ): PubAck {
                return $this->inner->publish($subject, $payload, $options, $timeoutMs);
            }
        };
        self::assertSame(1, $legacy->publish('s', 'payload')->getSeq());
    }
}
