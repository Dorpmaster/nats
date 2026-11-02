<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Dorpmaster\Nats\Client\WriteBufferService;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneHeadersRequestMessage;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HPubMessage;
use Dorpmaster\Nats\Protocol\OutboundFrameBuilder;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WriteBufferSnapshotTest extends TestCase
{
    use AsyncTestTools;

    #[DataProvider('messageTypes')]
    public function testPausedWriterSendsEnqueueSnapshotAndAccountsFullFrame(bool $jetstream, string|null $replyTo): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($jetstream, $replyTo): void {
            $headers = new HeaderBag(['X' => 'a']);
            $payload = "AB\r\n\0";
            $message = $jetstream
                ? new JetStreamControlPlaneHeadersRequestMessage('audit.example', $payload, $headers, $replyTo)
                : new HPubMessage('audit.example', $payload, $headers, $replyTo);
            $builder = new OutboundFrameBuilder();
            $frame   = $builder->build($message);
            $tail    = $builder->build(new PubMessage('audit.tail', 'end'));
            $sent    = [];
            // This third-party implementation needs only the unchanged ConnectionInterface.
            $connection = $this->createMock(ConnectionInterface::class);
            $connection->method('isClosed')->willReturn(false);
            $connection->expects(self::exactly(2))->method('send')->willReturnCallback(static function (NatsProtocolMessageInterface $message) use (&$sent): void {
                $sent[] = (string) $message;
            });
            $buffer = new WriteBufferService(10, 10000);
            $buffer->pause();
            $buffer->start($connection);
            try {
                self::assertTrue($buffer->enqueue($frame));
                self::assertTrue($buffer->enqueue($tail));
                $headers->set('X', 'abcdefgh');
                $this->forceTick();
                self::assertSame([], $sent);
                self::assertSame(2, $buffer->getPendingMessages());
                self::assertSame(strlen($frame->data) + strlen($tail->data), $buffer->getPendingBytes());
                self::assertSame(strlen($frame->data), $frame->bytes);
                $buffer->resume();
                $this->forceTick();
                self::assertSame([$frame->data, $tail->data], $sent);
                self::assertSame(0, $buffer->getPendingBytes());
                self::assertSame(0, $buffer->getPendingMessages());
                // The caller's original mutable header bag remains available.
                self::assertSame('abcdefgh', $message->getHeaders()->get('X'));
            } finally {
                $buffer->stop();
                $this->forceTick();
            }
        });
    }

    #[DataProvider('messageTypes')]
    public function testFailedWriteRetriesIdenticalSnapshotWithoutDoubleCounting(bool $jetstream, string|null $replyTo): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () use ($jetstream, $replyTo): void {
            $headers    = new HeaderBag(['X' => 'a']);
            $message    = $jetstream
                ? new JetStreamControlPlaneHeadersRequestMessage('audit.example', 'AB', $headers, $replyTo)
                : new HPubMessage('audit.example', 'AB', $headers, $replyTo);
            $frame      = (new OutboundFrameBuilder())->build($message);
            $sent       = [];
            $connection = $this->createMock(ConnectionInterface::class);
            $connection->method('isClosed')->willReturn(false);
            $connection->expects(self::exactly(2))->method('send')->willReturnCallback(static function (NatsProtocolMessageInterface $message) use (&$sent): void {
                $sent[] = (string) $message;
                if (count($sent) === 1) {
                    throw new ConnectionException('Synthetic first-write failure');
                }
            });
            $buffer = new WriteBufferService(10, 10000);
            $buffer->pause();
            $buffer->start($connection);
            try {
                $buffer->enqueue($frame);
                $headers->set('X', 'abcdefgh');
                $buffer->resume();
                $this->forceTick();
                self::assertSame([$frame->data], $sent);
                self::assertTrue($buffer->hasFailedFrame());
                self::assertSame(1, $buffer->getPendingMessages());
                self::assertSame($frame->bytes, $buffer->getPendingBytes());
                $message->getHeaders()->set('X', 'changed again before retry');
                $buffer->start($connection);
                $this->forceTick();
                self::assertSame([$frame->data, $frame->data], $sent);
                self::assertFalse($buffer->hasFailedFrame());
                self::assertSame(0, $buffer->getPendingMessages());
                self::assertSame(0, $buffer->getPendingBytes());
            } finally {
                $buffer->stop();
                $this->forceTick();
            }
        });
    }

    public static function messageTypes(): iterable
    {
        foreach ([false, true] as $jetstream) {
            foreach ([null, 'audit.reply'] as $replyTo) {
                yield ($jetstream ? 'jetstream' : 'core') . ($replyTo === null ? '-no-reply' : '-reply') => [$jetstream, $replyTo];
            }
        }
    }
}
