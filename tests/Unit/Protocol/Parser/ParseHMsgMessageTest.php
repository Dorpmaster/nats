<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol\Parser;

use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\HMsgMessage;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use Dorpmaster\Nats\Tests\AsyncTestCase;

final class ParseHMsgMessageTest extends AsyncTestCase
{
    public function testMessageNoReply(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $isParsed = false;

            $callback = function (HMsgMessageInterface $message) use(&$isParsed): void {
                self::assertInstanceOf(HMsgMessage::class, $message);

                self::assertSame('FOO.BAR', $message->getSubject());
                self::assertSame('9', $message->getSid());
                self::assertNull($message->getReplyTo());
                self::assertSame(11, $message->getPayloadSize());
                self::assertSame(48, $message->getHeadersSize());
                self::assertSame(59, $message->getTotalSize());
                self::assertSame('Hello World', $message->getPayload());

                $headers = $message->getHeaders();
                self::assertSame(['X', 'Y'], $headers->get('Header1'));
                self::assertSame('Z', $headers->get('Header2'));

                $isParsed = true;
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield "HMSG FOO.BAR 9 48 59\r\nNATS/1.0\r\nHeader1: X\r\nHeader1: Y\r\nHeader2: Z\r\n\r\nHello World\r\n";
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

            $callback = function (HMsgMessageInterface $message) use(&$isParsed): void {
                self::assertInstanceOf(HMsgMessage::class, $message);

                self::assertSame('FOO.BAR', $message->getSubject());
                self::assertSame('9', $message->getSid());
                self::assertSame('GREETING.34', $message->getReplyTo());
                self::assertSame(11, $message->getPayloadSize());
                self::assertSame(48, $message->getHeadersSize());
                self::assertSame(59, $message->getTotalSize());
                self::assertSame('Hello World', $message->getPayload());

                $headers = $message->getHeaders();
                self::assertSame(['X', 'Y'], $headers->get('Header1'));
                self::assertSame('Z', $headers->get('Header2'));

                $isParsed = true;
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield "HMSG FOO.BAR 9 GREETING.34 48 59\r\nNATS/1.0\r\nHeader1: X\r\nHeader1: Y\r\nHeader2: Z\r\n\r\nHello World\r\n";
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

            $callback = function (HMsgMessageInterface $message) use(&$isParsed): void {
                self::assertInstanceOf(HMsgMessage::class, $message);

                self::assertSame('FOO.BAR', $message->getSubject());
                self::assertSame('9', $message->getSid());
                self::assertSame('GREETING.34', $message->getReplyTo());
                self::assertSame(11, $message->getPayloadSize());
                self::assertSame(48, $message->getHeadersSize());
                self::assertSame(59, $message->getTotalSize());
                self::assertSame('Hello World', $message->getPayload());

                $headers = $message->getHeaders();
                self::assertSame(['X', 'Y'], $headers->get('Header1'));
                self::assertSame('Z', $headers->get('Header2'));

                $isParsed = true;
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield "HMSG   FOO.BAR 9    GREETING.34   \t 48 59\r\nNATS/1.0\r\nHeader1: X\r\nHeader1: Y\r\nHeader2: Z\r\n\r\nHello World\r\n";
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
            $callback = function (HMsgMessageInterface $message): void {
                self::assertInstanceOf(HMsgMessage::class, $message);
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield "HMSG bla-bla-bla\r\n";
            };

            self::expectException(\RuntimeException::class);
            self::expectExceptionMessage('Malformed HMSG message');
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
            $callback = function (HMsgMessageInterface $message): void {
                self::assertInstanceOf(HMsgMessage::class, $message);
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
