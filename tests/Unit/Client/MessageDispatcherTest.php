<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Client\SlowConsumerPolicy;
use Dorpmaster\Nats\Domain\Client\SlowConsumerException;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Protocol\Contracts\ConnectMessageInterface;
use Dorpmaster\Nats\Protocol\ErrMessage;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HMsgMessage;
use Dorpmaster\Nats\Protocol\InfoMessage;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\Metadata\ServerInfo;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\OkMessage;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Protocol\PongMessage;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function Amp\async;

final class MessageDispatcherTest extends TestCase
{
    public function testDispatchInfo(): void
    {
        $storage     = self::createStub(SubscriptionStorageInterface::class);
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createStub(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $storage, $logger);

        $info        = [
            'server_id' => 'id',
            'server_name' => 'nats',
            'version' => '1',
            'go' => 'go',
            'host' => 'host',
            'port' => 4321,
            'headers' => true,
            'max_payload' => 1234,
            'proto' => 1,
        ];
        $infoMessage = new InfoMessage(json_encode($info));

        $response = $dispatcher->dispatch($infoMessage);
        self::assertInstanceOf(ConnectMessageInterface::class, $response);
    }

    public function testGetServerInfo(): void
    {
        $storage     = self::createStub(SubscriptionStorageInterface::class);
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createStub(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $storage, $logger);

        $info = [
            'server_id' => 'id',
            'server_name' => 'nats',
            'version' => '1',
            'go' => 'go',
            'host' => 'host',
            'port' => 4321,
            'headers' => true,
            'max_payload' => 1234,
            'proto' => 1,
        ];

        self::assertNull($dispatcher->getServerInfo());

        $infoMessage = new InfoMessage(json_encode($info));
        $response    = $dispatcher->dispatch($infoMessage);
        self::assertInstanceOf(ConnectMessageInterface::class, $response);

        $serverInfo = $dispatcher->getServerInfo();
        self::assertInstanceOf(ServerInfo::class, $serverInfo);
        self::assertSame('id', $serverInfo->server_id);
        self::assertSame('nats', $serverInfo->server_name);
        self::assertSame('1', $serverInfo->version);
        self::assertSame('go', $serverInfo->go);
        self::assertSame('host', $serverInfo->host);
        self::assertSame(4321, $serverInfo->port);
        self::assertTrue($serverInfo->headers);
        self::assertSame(1234, $serverInfo->max_payload);
        self::assertSame(1, $serverInfo->proto);
    }

    public function testDispatchMsgWithResponse(): void
    {
        $storage = self::createMock(SubscriptionStorageInterface::class);
        $storage->expects(self::once())
            ->method('get')
            ->willReturnCallback(static function (string $sid): callable {
                self::assertSame('sid', $sid);

                return static fn() => new PubMessage('response', 'payload');
            });

        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createStub(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $storage, $logger);

        $message = new MsgMessage(
            'subject',
            'sid',
            'payload',
        );

        $response = $dispatcher->dispatch($message);
        self::assertInstanceOf(PubMessage::class, $response);
        self::assertSame('response', $response->getSubject());
        self::assertSame('payload', $response->getPayload());
    }

    public function testDispatchInvokesSubscriptionHandlerInlineBeforeReturning(): void
    {
        $events  = [];
        $storage = self::createStub(SubscriptionStorageInterface::class);
        $storage->method('get')->willReturn(static function () use (&$events): null {
            $events[] = 'handler';

            return null;
        });

        $dispatcher = new MessageDispatcher(
            new ConnectInfo(false, false, false, 'php', '8.3'),
            $storage,
            self::createStub(LoggerInterface::class),
        );

        $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'payload'));
        $events[] = 'returned';

        self::assertSame(['handler', 'returned'], $events);
    }

    public function testDispatchReturnsOnlyAfterSubscriptionHandlerCompletes(): void
    {
        $release = new DeferredFuture();
        $storage = self::createStub(SubscriptionStorageInterface::class);
        $storage->method('get')->willReturn(static function () use ($release): null {
            $release->getFuture()->await(new TimeoutCancellation(1));

            return null;
        });

        $dispatcher = new MessageDispatcher(
            new ConnectInfo(false, false, false, 'php', '8.3'),
            $storage,
            self::createStub(LoggerInterface::class),
        );

        $future = async(static fn() => $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'payload')));

        self::assertFalse($future->isComplete());

        $release->complete();

        self::assertNull($future->await(new TimeoutCancellation(1)));
    }

    public function testDispatchMsgNoResponse(): void
    {
        $storage = self::createMock(SubscriptionStorageInterface::class);
        $storage->expects(self::once())
            ->method('get')
            ->willReturnCallback(static function (string $sid): null {
                self::assertSame('sid', $sid);

                return null;
            });

        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createStub(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $storage, $logger);

        $message = new MsgMessage(
            'subject',
            'sid',
            'payload',
        );

        $response = $dispatcher->dispatch($message);
        self::assertNull($response);
    }

    public function testDispatchMsgWrongResponse(): void
    {
        $storage = self::createMock(SubscriptionStorageInterface::class);
        $storage->expects(self::once())
            ->method('get')
            ->willReturnCallback(static function (string $sid): callable {
                self::assertSame('sid', $sid);

                return static fn() => new PingMessage();
            });

        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createStub(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $storage, $logger);

        $message = new MsgMessage(
            'subject',
            'sid',
            'payload',
        );

        $response = $dispatcher->dispatch($message);
        self::assertNull($response);
    }

    public function testDispatchHMsg(): void
    {
        $storage = self::createMock(SubscriptionStorageInterface::class);
        $storage->expects(self::once())
            ->method('get')
            ->willReturnCallback(static function (string $sid): callable {
                self::assertSame('sid', $sid);

                return static fn() => new PubMessage('response', 'payload');
            });

        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createStub(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $storage, $logger);

        $message = new HMsgMessage(
            'subject',
            'sid',
            'payload',
            new HeaderBag(['a' => 'b']),
        );

        $response = $dispatcher->dispatch($message);

        self::assertInstanceOf(PubMessage::class, $response);
        self::assertSame('response', $response->getSubject());
        self::assertSame('payload', $response->getPayload());
    }

    public function testDispatchPing(): void
    {
        $storage     = self::createStub(SubscriptionStorageInterface::class);
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createStub(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $storage, $logger);

        $message = new PingMessage();

        $response = $dispatcher->dispatch($message);
        self::assertInstanceOf(PongMessage::class, $response);
    }

    public function testDispatchOk(): void
    {
        $storage     = self::createStub(SubscriptionStorageInterface::class);
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createStub(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $storage, $logger);

        $message = new OkMessage();

        $response = $dispatcher->dispatch($message);
        self::assertNull($response);
    }

    public function testDispatchErr(): void
    {
        $storage     = self::createStub(SubscriptionStorageInterface::class);
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createStub(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $storage, $logger);

        $message = new ErrMessage('payload');

        $response = $dispatcher->dispatch($message);
        self::assertNull($response);
    }

    public function testPendingMessagesExceedTriggersErrorPolicy(): void
    {
        // Arrange
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $storage     = self::createStub(SubscriptionStorageInterface::class);
        $metrics     = new RecordingMetricsCollector();

        $dispatcher = null;
        $storage->method('get')
            ->willReturn(static function () use (&$dispatcher) {
                assert($dispatcher instanceof MessageDispatcher);

                // Re-entrant dispatch keeps pending counter acquired and triggers slow consumer.
                $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'payload'));

                return null;
            });

        $dispatcher = new MessageDispatcher(
            $connectInfo,
            $storage,
            null,
            maxPendingMessagesPerSubscription: 1,
            slowConsumerPolicy: SlowConsumerPolicy::ERROR,
            metricsCollector: $metrics,
        );

        // Act
        try {
            $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'payload'));
            self::fail('Expected SlowConsumerException');
        } catch (SlowConsumerException $exception) {
            // Assert
            self::assertStringContainsString('Slow consumer detected for sid "sid"', $exception->getMessage());
            self::assertSame(1, $metrics->countIncrements('slow_consumer_events'));
        }
    }

    public function testDropNewPolicyDropsOverflowAndKeepsDispatcherWorking(): void
    {
        // Arrange
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $storage     = self::createStub(SubscriptionStorageInterface::class);
        $calls       = 0;
        $metrics     = new RecordingMetricsCollector();

        $dispatcher = null;
        $storage->method('get')
            ->willReturn(static function () use (&$dispatcher, &$calls) {
                $calls++;
                if ($calls === 1) {
                    assert($dispatcher instanceof MessageDispatcher);
                    $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'payload-overflow'));
                }

                return null;
            });

        $dispatcher = new MessageDispatcher(
            $connectInfo,
            $storage,
            null,
            maxPendingMessagesPerSubscription: 1,
            slowConsumerPolicy: SlowConsumerPolicy::DROP_NEW,
            metricsCollector: $metrics,
        );

        // Act
        $response1 = $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'payload-1'));
        $response2 = $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'payload-2'));

        // Assert
        self::assertNull($response1);
        self::assertNull($response2);
        self::assertSame(2, $calls);
        self::assertSame(1, $metrics->countIncrements('dropped_messages'));
    }

    public function testPendingBytesExceedTriggersErrorPolicy(): void
    {
        // Arrange
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $storage     = self::createStub(SubscriptionStorageInterface::class);
        $storage->method('get')->willReturn(static fn(): null => null);

        $dispatcher = new MessageDispatcher(
            $connectInfo,
            $storage,
            null,
            maxPendingMessagesPerSubscription: 10,
            slowConsumerPolicy: SlowConsumerPolicy::ERROR,
            maxPendingBytesPerSubscription: 5,
        );

        // Assert
        self::expectException(SlowConsumerException::class);
        self::expectExceptionMessage('Slow consumer detected for sid "sid"');

        // Act
        $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'payload'));
    }

    public function testPendingBytesDropNewPolicyDropsOverflowAndKeepsDispatcherWorking(): void
    {
        // Arrange
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $storage     = self::createStub(SubscriptionStorageInterface::class);
        $calls       = 0;
        $dispatcher  = null;

        $storage->method('get')
            ->willReturn(static function () use (&$calls, &$dispatcher) {
                $calls++;
                if ($calls === 1) {
                    assert($dispatcher instanceof MessageDispatcher);
                    $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'aaaa'));
                }

                return null;
            });

        $dispatcher = new MessageDispatcher(
            $connectInfo,
            $storage,
            null,
            maxPendingMessagesPerSubscription: 10,
            slowConsumerPolicy: SlowConsumerPolicy::DROP_NEW,
            maxPendingBytesPerSubscription: 5,
        );

        // Act
        $response1 = $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'bbb'));
        $response2 = $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'ccc'));

        // Assert
        self::assertNull($response1);
        self::assertNull($response2);
        self::assertSame(2, $calls);
    }

    public function testPendingBytesAreReleasedAfterHandlerCompletes(): void
    {
        // Arrange
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $storage     = self::createStub(SubscriptionStorageInterface::class);
        $calls       = 0;

        $storage->method('get')
            ->willReturn(static function () use (&$calls): null {
                $calls++;

                return null;
            });

        $dispatcher = new MessageDispatcher(
            $connectInfo,
            $storage,
            null,
            maxPendingMessagesPerSubscription: 10,
            slowConsumerPolicy: SlowConsumerPolicy::ERROR,
            maxPendingBytesPerSubscription: 5,
        );

        // Act
        $response1 = $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'aaaaa'));
        $response2 = $dispatcher->dispatch(new MsgMessage('subject', 'sid', 'bbbbb'));

        // Assert
        self::assertNull($response1);
        self::assertNull($response2);
        self::assertSame(2, $calls);
    }

    public function testReleaseWithoutPriorIncrementDoesNotCreateNegativePendingBytes(): void
    {
        // Arrange
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $storage     = self::createStub(SubscriptionStorageInterface::class);
        $dispatcher  = new MessageDispatcher($connectInfo, $storage);
        $invoke      = \Closure::bind(
            static function (MessageDispatcher $target): array {
                $target->releasePendingSlot('sid', 42);

                return [
                    $target->pendingMessagesBySid,
                    $target->pendingBytesBySid,
                ];
            },
            null,
            MessageDispatcher::class,
        );

        // Act
        [$pendingMessagesBySid, $pendingBytesBySid] = $invoke($dispatcher);

        // Assert
        self::assertArrayNotHasKey('sid', $pendingMessagesBySid);
        self::assertArrayNotHasKey('sid', $pendingBytesBySid);
    }
}
