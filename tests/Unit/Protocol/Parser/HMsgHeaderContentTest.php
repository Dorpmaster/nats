<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol\Parser;

use Dorpmaster\Nats\Protocol\HMsgMessage;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use Dorpmaster\Nats\Tests\Support\HMsgHeaderCases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HMsgHeaderContentTest extends TestCase
{
    public static function headerCases(): iterable
    {
        foreach (HMsgHeaderCases::cases() as $name => [$headers]) {
            yield $name . ' without status' => [$headers, false];
            yield $name . ' with status' => [$headers, true];
        }
    }

    /** @param array<string, list<string>> $headers */
    #[DataProvider('headerCases')]
    public function testHeaderContentSurvivesChunkingAndFollowingFrames(array $headers, bool $withStatus): void
    {
        $block  = $withStatus ? 'NATS/1.0 100 Idle Heartbeat: NATS/1.0' : 'NATS/1.0';
        $block .= "\r\n";
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $block .= "$name: $value\r\n";
            }
        }

        $block   .= "\r\n";
        $payload  = "binary\r\n\0HMSG is payload\r\nend\0";
        $received = self::parse($block, $payload, 1);
        self::assertCount(3, $received);
        self::assertInstanceOf(HMsgMessage::class, $received[0]);
        self::assertSame($headers, iterator_to_array($received[0]->getHeaders()));
        self::assertSame($payload, $received[0]->getPayload());
        self::assertSame(strlen($payload), $received[0]->getPayloadSize());
        self::assertSame(strlen($block) + strlen($payload), $received[0]->getWireBodySize());
        if ($withStatus) {
            self::assertSame(100, $received[0]->getStatus()->code);
            self::assertSame('Idle Heartbeat: NATS/1.0', $received[0]->getStatus()->description);
        } else {
            self::assertNull($received[0]->getStatus());
        }

        self::assertSame(NatsMessageType::PING, $received[1]->getType());
        self::assertSame(NatsMessageType::MSG, $received[2]->getType());
        self::assertSame('following', $received[2]->getSubject());
        self::assertSame("next\0\r\n", $received[2]->getPayload());
    }

    public function testCaseWhitespaceAndHeaderNamesWithNatsArePreserved(): void
    {
        $block   = "NATS/1.0\r\nX:   first  \t\r\nx:\tsecond\t\r\nX: \tNATS/1.0 \t\r\nNATS/Key: https://example.test/a:b:c\r\n\r\n";
        $message = self::parse($block, '', 7)[0];
        self::assertSame([
            'X' => ['first', 'NATS/1.0'],
            'x' => ['second'],
            'NATS/Key' => ['https://example.test/a:b:c'],
        ], iterator_to_array($message->getHeaders()));
        self::assertSame(strlen($block), $message->getWireBodySize());
    }

    public static function invalidPreambles(): iterable
    {
        yield ['NATS/2.0'];
        yield ['X: NATS/1.0'];
        yield ['NATS/1.0 unexpected'];
    }

    #[DataProvider('invalidPreambles')]
    public function testPreambleMustHaveValidFormatAtFirstPosition(string $preamble): void
    {
        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Malformed HMSG header preamble');
        self::parse($preamble . "\r\nX: kept\r\n\r\n", '', 1);
    }

    /** @return list<\Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface> */
    private static function parse(string $block, string $payload, int $chunkSize): array
    {
        $received    = [];
        $parser      = new ProtocolParser(static function ($message) use (&$received): void {
            $received[] = $message;
        });
        $headersSize = strlen($block);
        $total       = $headersSize + strlen($payload);
        $wire        = "HMSG incoming sid ACK.INCOMING $headersSize $total\r\n$block$payload\r\n";
        $wire       .= "PING\r\nMSG following sid 7\r\nnext\0\r\n\r\n";
        foreach (str_split($wire, $chunkSize) as $chunk) {
            $parser->push($chunk);
        }

        return $received;
    }
}
