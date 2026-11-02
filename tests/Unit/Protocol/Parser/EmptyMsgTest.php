<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol\Parser;

use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use Dorpmaster\Nats\Protocol\PingMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmptyMsgTest extends TestCase
{
    public static function fragments(): iterable
    {
        $wire = "MSG reply 1 0\r\n\r\nPING\r\nMSG next 2 3\r\nA\0B\r\n";
        yield 'one buffer' => [[$wire]];
        yield 'byte by byte' => [str_split($wire)];
        for ($split = 1; $split < strlen($wire); $split++) {
            yield 'split ' . $split => [[substr($wire, 0, $split), substr($wire, $split)]];
        }
    }

    #[DataProvider('fragments')]
    public function testEmptyMsgThenPingAndBinaryMsg(array $chunks): void
    {
        $messages = [];
        $parser   = new ProtocolParser(static function ($message) use (&$messages): void {
            $messages[] = $message;
        });
        foreach ($chunks as $chunk) {
            $parser->push($chunk);
        }
        self::assertCount(3, $messages);
        self::assertInstanceOf(MsgMessage::class, $messages[0]);
        self::assertSame('', $messages[0]->getPayload());
        self::assertSame(0, $messages[0]->getPayloadSize());
        self::assertInstanceOf(PingMessage::class, $messages[1]);
        self::assertSame("A\0B", $messages[2]->getPayload());
        $parser->cancel();
    }

    public function testSplitDelimiterDoesNotDispatchEarly(): void
    {
        $messages = [];
        $parser   = new ProtocolParser(static function ($message) use (&$messages): void {
            $messages[] = $message;
        });
        $parser->push("MSG reply 1 original 0\r\n\r");
        self::assertSame([], $messages);
        $parser->push("\nPING\r\n");
        self::assertCount(2, $messages);
        self::assertSame('original', $messages[0]->getReplyTo());
        self::assertSame('', $messages[0]->getPayload());
    }

    public static function invalidDelimiters(): iterable
    {
        foreach (["\rX", "X\n", "\n\r"] as $delimiter) {
            yield [$delimiter];
        }
    }

    #[DataProvider('invalidDelimiters')]
    public function testRejectsCorruptEmptyPayloadDelimiter(string $delimiter): void
    {
        $messages = [];
        $parser   = new ProtocolParser(static function ($message) use (&$messages): void {
            $messages[] = $message;
        });
        try {
            $parser->push("MSG reply 1 0\r\n" . $delimiter . "PING\r\n");
            self::fail('Expected delimiter rejection');
        } catch (\RuntimeException $error) {
            self::assertSame('Malformed MSG message payload delimiter', $error->getMessage());
            self::assertSame([], $messages);
        }
    }
}
