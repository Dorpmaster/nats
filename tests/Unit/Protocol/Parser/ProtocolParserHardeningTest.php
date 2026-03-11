<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol\Parser;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\HMsgMessage;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\InfoMessage;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Protocol\PongMessage;
use Dorpmaster\Nats\Tests\Support\ProtocolChunkFeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtocolParserHardeningTest extends TestCase
{
    #[DataProvider('fragmentedFramesProvider')]
    public function testParsesFragmentedFrames(array $chunks, string $expectedClass, callable $assertMessage): void
    {
        // Arrange
        $feeder = new ProtocolChunkFeeder();

        // Act
        $feeder->feed($chunks);
        $feeder->cancel();
        $messages = $feeder->messages();

        // Assert
        self::assertCount(1, $messages);
        self::assertInstanceOf($expectedClass, $messages[0]);
        $assertMessage($messages[0]);
    }

    /** @return iterable<string, array{0: list<string>, 1: class-string, 2: callable}> */
    public static function fragmentedFramesProvider(): iterable
    {
        yield 'info-fragmented' => [
            [
                'IN',
                "FO {\"server_id\":\"id\",\"server_name\":\"nats\",\"version\":\"1\",\"go\":\"go\",\"host\":\"127.0.0.1\",\"port\":4222,\"headers\":true,\"max_payload\":1024,\"proto\":1}\r\n",
            ],
            InfoMessage::class,
            static function (NatsProtocolMessageInterface $message): void {
                self::assertInstanceOf(InfoMessage::class, $message);
                self::assertSame('nats', $message->getServerInfo()->server_name);
            },
        ];

        yield 'ping-fragmented' => [
            ['PI', "NG\r\n"],
            PingMessage::class,
            static function (NatsProtocolMessageInterface $message): void {
                self::assertInstanceOf(PingMessage::class, $message);
            },
        ];

        yield 'pong-fragmented' => [
            ['PO', "NG\r\n"],
            PongMessage::class,
            static function (NatsProtocolMessageInterface $message): void {
                self::assertInstanceOf(PongMessage::class, $message);
            },
        ];

        yield 'msg-fragmented' => [
            ["MSG s 1 5\r\nhe", "llo\r\n"],
            MsgMessage::class,
            static function (NatsProtocolMessageInterface $message): void {
                self::assertInstanceOf(MsgMessage::class, $message);
                self::assertSame('s', $message->getSubject());
                self::assertSame('1', $message->getSid());
                self::assertSame('hello', $message->getPayload());
            },
        ];

        $hmsg = (string) new HMsgMessage('s', '1', 'hello', new HeaderBag(['K' => 'V']));
        yield 'hmsg-fragmented' => [
            [
                substr($hmsg, 0, 18),
                substr($hmsg, 18, 14),
                substr($hmsg, 32),
            ],
            HMsgMessage::class,
            static function (NatsProtocolMessageInterface $message): void {
                self::assertInstanceOf(HMsgMessage::class, $message);
                self::assertSame('hello', $message->getPayload());
                self::assertSame('V', $message->getHeaders()->get('K'));
            },
        ];
    }

    #[DataProvider('coalescedFramesProvider')]
    public function testParsesCoalescedFrames(string $chunk, array $expectedTypes): void
    {
        // Arrange
        $feeder = new ProtocolChunkFeeder();

        // Act
        $feeder->feed([$chunk]);
        $feeder->cancel();
        $messages = $feeder->messages();

        // Assert
        self::assertSame($expectedTypes, array_map(static fn(NatsProtocolMessageInterface $m): string => $m->getType()->value, $messages));
    }

    /** @return iterable<string, array{0: string, 1: list<string>}> */
    public static function coalescedFramesProvider(): iterable
    {
        $info = "INFO {\"server_id\":\"id\",\"server_name\":\"nats\",\"version\":\"1\",\"go\":\"go\",\"host\":\"127.0.0.1\",\"port\":4222,\"headers\":true,\"max_payload\":1024,\"proto\":1}\r\n";

        yield 'two-ping-frames' => ["PING\r\nPING\r\n", ['PING', 'PING']];
        yield 'ping-and-pong' => ["PING\r\nPONG\r\n", ['PING', 'PONG']];
        yield 'ping-and-msg' => ["PING\r\nMSG s 1 5\r\nhello\r\n", ['PING', 'MSG']];
        yield 'info-and-ping' => [$info . "PING\r\n", ['INFO', 'PING']];
        yield 'info-and-pong' => [$info . "PONG\r\n", ['INFO', 'PONG']];
    }

    public function testParsesMsgWithExtraSpacesInMetadata(): void
    {
        // Arrange
        $feeder = new ProtocolChunkFeeder();

        // Act
        $feeder->feed(["MSG   s   1   5\r\nhello\r\n"]);
        $feeder->cancel();
        $messages = $feeder->messages();

        // Assert
        self::assertCount(1, $messages);
        self::assertInstanceOf(MsgMessage::class, $messages[0]);
        self::assertSame('hello', $messages[0]->getPayload());
    }

    public function testDoesNotEmitFrameWithoutCrLfTerminator(): void
    {
        // Arrange
        $feeder = new ProtocolChunkFeeder();

        // Act
        $feeder->feed(["PING\n"]);
        $feeder->cancel();

        // Assert
        self::assertSame([], $feeder->messages());
        self::assertNull($feeder->remainder());
    }

    #[DataProvider('invalidFramesProvider')]
    public function testRejectsInvalidFrames(array $chunks, string $expectedMessage): void
    {
        // Arrange
        $feeder = new ProtocolChunkFeeder();

        // Assert
        self::expectException(\Throwable::class);
        self::expectExceptionMessage($expectedMessage);

        // Act
        $feeder->feed($chunks);
    }

    /** @return iterable<string, array{0: list<string>, 1: string}> */
    public static function invalidFramesProvider(): iterable
    {
        yield 'unknown-command' => [["PUNG\r\n"], 'Unknown message type "PUNG"'];
        yield 'msg-size-non-numeric' => [["MSG s 1 abc\r\nhello\r\n"], 'MSG payload size must be numeric'];
        yield 'msg-size-negative' => [["MSG s 1 -1\r\nhello\r\n"], 'MSG payload size must be numeric'];
        yield 'msg-empty-subject' => [["MSG  1 5\r\nhello\r\n"], 'Malformed MSG message'];
        yield 'msg-payload-delimiter-mismatch' => [["MSG s 1 5\r\nhell\r\nPING\r\n"], 'Malformed MSG message payload delimiter'];
        yield 'hmsg-size-non-numeric' => [["HMSG s 1 a 5\r\nhello\r\n"], 'HMSG sizes must be numeric'];
        yield 'hmsg-size-relation-invalid' => [["HMSG s 1 10 5\r\nabcde\r\n"], 'Malformed HMSG message sizes'];
    }

    public function testParserIsNotWritableAfterErrorAndDoesNotEmitGarbage(): void
    {
        // Arrange
        $feeder = new ProtocolChunkFeeder();

        // Act
        try {
            $feeder->push("PUNG\r\n");
            self::fail('Parser should fail on unknown command');
        } catch (\RuntimeException $exception) {
            // Assert
            self::assertSame('Unknown message type "PUNG"', $exception->getMessage());
        }

        // Act + Assert
        self::expectException(\Error::class);
        self::expectExceptionMessage('The parser is no longer writable');
        $feeder->push("PING\r\n");
    }
}
