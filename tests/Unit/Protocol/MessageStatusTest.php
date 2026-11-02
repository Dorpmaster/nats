<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\HMsgMessage;
use Dorpmaster\Nats\Protocol\MessageStatus;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageStatusTest extends TestCase
{
    public static function statuses(): iterable
    {
        yield [100, 'Idle Heartbeat'];
        yield [404, 'No Messages'];
        yield [408, 'Request Timeout'];
        yield [409, 'Message Size Exceeds MaxBytes'];
        yield [503, 'Failure: synthetic description'];
        yield [200, ''];
    }

    #[DataProvider('statuses')]
    public function testStatusOnlyHmsgSurvivesChunkingAndNextFrame(int $code, string $description): void
    {
        $received = [];
        $parser   = new ProtocolParser(static function ($message) use (&$received): void {
            $received[] = $message;
        });
        $block    = "NATS/1.0 $code" . ($description === '' ? '' : " $description") . "\r\n\r\n";
        $size     = strlen($block);
        $wire     = "HMSG inbox sid $size $size\r\n$block\r\n";
        foreach (str_split($wire . "PING\r\n", 3) as $chunk) {
            $parser->push($chunk);
        }

        self::assertCount(2, $received);
        self::assertInstanceOf(HMsgMessage::class, $received[0]);
        self::assertSame($code, $received[0]->getStatus()->code);
        self::assertSame($description, $received[0]->getStatus()->description);
        self::assertSame('', $received[0]->getPayload());
        self::assertCount(0, $received[0]->getHeaders());
        self::assertSame($size, $received[0]->getHeadersSize());
        self::assertSame($wire, (string) $received[0]);
        self::assertSame(NatsMessageType::PING, $received[1]->getType());
    }

    public function testStatusAndOrdinaryHeadersAreSeparateAndPreserveColons(): void
    {
        $message = null;
        $parser  = new ProtocolParser(static function ($parsed) use (&$message): void {
            $message = $parsed;
        });
        $block   = "NATS/1.0 100 Idle Heartbeat\r\nNats-Last-Consumer: 1\r\nX: a:b\r\n\r\n";
        $size    = strlen($block);
        $parser->push("HMSG inbox sid $size $size\r\n$block\r\n");
        self::assertSame(100, $message->getStatus()->code);
        self::assertSame('1', $message->getHeaders()->get('Nats-Last-Consumer'));
        self::assertSame('a:b', $message->getHeaders()->get('X'));
        self::assertNull(MessageStatus::fromHeaderBlock("NATS/1.0\r\nX: a\r\n\r\n"));
    }
}
