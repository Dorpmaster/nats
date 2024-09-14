<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol\Parser;

use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use Dorpmaster\Nats\Tests\AsyncTestCase;

final class ParseMsgMessageTest extends AsyncTestCase
{
    public function testMessageNoReply(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $isParsed = false;

            $callback = function (MsgMessageInterface $message) use(&$isParsed): void {
                self::assertInstanceOf(MsgMessage::class, $message);

                self::assertSame('FOO.BAR', $message->getSubject());
                self::assertSame('9', $message->getSid());
                self::assertNull($message->getReplyTo());
                self::assertSame(11, $message->getPayloadSize());
                self::assertSame('Hello World', $message->getPayload());

                $isParsed = true;
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield "MSG FOO.BAR 9 11\r\nHello World\r\n";
            };

            foreach ($source() as $chunk) {
                $parser->push($chunk);
            }

            $parser->cancel();

            if ($isParsed !== true) {
                self::fail('The message has not been parsed');
            }
        });
    }

    public function testMessageReply(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $isParsed = false;

            $callback = function (MsgMessageInterface $message) use(&$isParsed): void {
                self::assertInstanceOf(MsgMessage::class, $message);

                self::assertSame('FOO.BAR', $message->getSubject());
                self::assertSame('9', $message->getSid());
                self::assertSame('GREETING.34', $message->getReplyTo());
                self::assertSame(11, $message->getPayloadSize());
                self::assertSame('Hello World', $message->getPayload());

                $isParsed = true;
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield "MSG FOO.BAR 9 GREETING.34 11\r\nHello World\r\n";
            };

            foreach ($source() as $chunk) {
                $parser->push($chunk);
            }

            $parser->cancel();

            if ($isParsed !== true) {
                self::fail('The message has not been parsed');
            }
        });
    }

    public function testMultipleSpaces(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $isParsed = false;

            $callback = function (MsgMessageInterface $message) use(&$isParsed): void {
                self::assertInstanceOf(MsgMessage::class, $message);

                self::assertSame('FOO.BAR', $message->getSubject());
                self::assertSame('9', $message->getSid());
                self::assertSame('GREETING.34', $message->getReplyTo());
                self::assertSame(11, $message->getPayloadSize());
                self::assertSame('Hello World', $message->getPayload());

                $isParsed = true;
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield "MSG   FOO.BAR  9 GREETING.34   11\r\nHello World\r\n";
            };

            foreach ($source() as $chunk) {
                $parser->push($chunk);
            }

            $parser->cancel();

            if ($isParsed !== true) {
                self::fail('The message has not been parsed');
            }
        });
    }

    public function testBrokenPayload(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $callback = function (MsgMessageInterface $message): void {
                self::assertInstanceOf(MsgMessage::class, $message);
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield "MSG bla-bla-bla\r\n";
            };

            self::expectException(\RuntimeException::class);
            self::expectExceptionMessage('Malformed MSG message');
            foreach ($source() as $chunk) {
                $parser->push($chunk);
            }

            $parser->cancel();
        });
    }

    public function testWrongType(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $callback = function (MsgMessageInterface $message): void {
                self::assertInstanceOf(MsgMessage::class, $message);
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield "TEST test\r\n";
            };

            self::expectException(\RuntimeException::class);
            self::expectExceptionMessage('Unknown message type "TEST"');
            foreach ($source() as $chunk) {
                $parser->push($chunk);
            }

            $parser->cancel();
        });
    }
}
