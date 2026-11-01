<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneHeadersRequestMessage;
use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HPubMessage;
use Dorpmaster\Nats\Protocol\PingMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HPubFrameConsistencyTest extends TestCase
{
    #[DataProvider('messageTypes')]
    public function testHeaderGrowthAndGetterMutationKeepDeclaredLengthsAligned(bool $jetstream, string|null $replyTo): void
    {
        $headers = new HeaderBag(['X' => 'a']);
        $message = self::message($jetstream, $headers, 'AB', $replyTo);
        self::assertSame($headers, $message->getHeaders());
        self::assertSame(18, $message->getHeadersSize());
        self::assertSame(20, $message->getTotalSize());

        $headers->set('X', 'abcdefgh');
        $this->assertFrame($message, 25, 27, "NATS/1.0\r\nX: abcdefgh\r\n\r\n", 'AB');

        $message->getHeaders()->set('X', 'b');
        $this->assertFrame($message, 18, 20, "NATS/1.0\r\nX: b\r\n\r\n", 'AB');
    }

    #[DataProvider('messageTypes')]
    public function testBinaryPayloadAndRepeatedHeadersPreserveOrdering(bool $jetstream, string|null $replyTo): void
    {
        $headers = new HeaderBag(['X' => ['first', 'second'], 'Z' => 'last']);
        $payload = "A\r\n\0B";
        $message = self::message($jetstream, $headers, $payload, $replyTo);
        $headers->set('X', 'third', false);
        $block = "NATS/1.0\r\nX: first\r\nX: second\r\nX: third\r\nZ: last\r\n\r\n";
        $this->assertFrame($message, strlen($block), strlen($block) + strlen($payload), $block, $payload);
        self::assertSame(['first', 'second', 'third'], $message->getHeaders()->get('X'));
    }

    #[DataProvider('messageTypes')]
    public function testEachSerializationReadsHeaderBytesExactlyOnce(bool $jetstream, string|null $replyTo): void
    {
        $headers = $this->createMock(HeaderBugInterface::class);
        $headers->method('count')->willReturn(1);
        $headers->expects(self::once())->method('__toString')->willReturn("NATS/1.0\r\nX: abcdefgh");
        $message = self::message($jetstream, $headers, 'AB', $replyTo);
        $this->assertWire((string) $message, 25, 27, "NATS/1.0\r\nX: abcdefgh\r\n\r\n", 'AB');
    }

    public static function messageTypes(): iterable
    {
        foreach ([false, true] as $jetstream) {
            foreach ([null, 'audit.reply'] as $replyTo) {
                yield ($jetstream ? 'jetstream' : 'core') . ($replyTo === null ? '-no-reply' : '-reply') => [$jetstream, $replyTo];
            }
        }
    }

    private static function message(bool $jetstream, HeaderBugInterface $headers, string $payload, string|null $replyTo): HPubMessageInterface&NatsProtocolMessageInterface
    {
        return $jetstream
            ? new JetStreamControlPlaneHeadersRequestMessage('audit.example', $payload, $headers, $replyTo)
            : new HPubMessage('audit.example', $payload, $headers, $replyTo);
    }

    private function assertFrame(HPubMessageInterface&NatsProtocolMessageInterface $message, int $headerBytes, int $totalBytes, string $block, string $payload): void
    {
        $this->assertWire((string) $message, $headerBytes, $totalBytes, $block, $payload);
        self::assertSame($headerBytes, $message->getHeadersSize());
        self::assertSame($totalBytes, $message->getTotalSize());
        self::assertSame(strlen($payload), $message->getPayloadSize());
    }

    private function assertWire(string $wire, int $headerBytes, int $totalBytes, string $block, string $payload): void
    {
        // Parse the declared sizes independently and use them to locate the next frame.
        $stream  = $wire . (string) new PingMessage();
        $lineEnd = strpos($stream, "\r\n");
        self::assertNotFalse($lineEnd);
        $tokens = explode(' ', substr($stream, 0, $lineEnd));
        self::assertSame('HPUB', $tokens[0]);
        $declaredHeaders = (int) $tokens[count($tokens) - 2];
        $declaredTotal   = (int) $tokens[count($tokens) - 1];
        self::assertSame($headerBytes, $declaredHeaders);
        self::assertSame($totalBytes, $declaredTotal);
        $body = substr($stream, $lineEnd + 2, $declaredTotal);
        self::assertSame($block, substr($body, 0, $declaredHeaders));
        self::assertSame($payload, substr($body, $declaredHeaders));
        self::assertSame("\r\nPING\r\n", substr($stream, $lineEnd + 2 + $declaredTotal));
    }
}
