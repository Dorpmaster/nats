<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HPubMessage;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HPubMessageTest extends TestCase
{
    #[DataProvider('dataProvider')]
    public function testPayload(
        string      $payload,
        string      $subject,
        HeaderBugInterface $headers,
        string|null $replyTo,
        string      $toString,
        string|null $error = null,
    ): void
    {
        if ($error !== null) {
            self::expectException(InvalidArgumentException::class);
            self::expectExceptionMessage($error);
        }

        $message = new HPubMessage(
            subject: $subject,
            payload: $payload,
            headers: $headers,
            replyTo: $replyTo,
        );

        if ($error !== null) {
            return;
        }

        $headersSize = strlen((string) $headers) + 4;
        $totalSize =  $headersSize + strlen($payload);

        self::assertSame($toString, (string)$message);
        self::assertSame(NatsMessageType::HPUB, $message->getType());
        self::assertSame($subject, $message->getSubject());
        self::assertSame($headers, $message->getHeaders());
        self::assertSame($replyTo, $message->getReplyTo());
        self::assertEquals(strlen($payload), $message->getPayloadSize());
        self::assertEquals($headersSize, $message->getHeadersSize());
        self::assertEquals($totalSize, $message->getTotalSize());
    }

    public static function dataProvider(): array
    {
        return [
            'with-reply' => [
                'Test payload',
                'subject.with.reply',
                new HeaderBag(['one' => 'value']),
                'reply.to',
                "HPUB subject.with.reply reply.to 24 36\r\nNATS/1.0\r\none: value\r\n\r\nTest payload\r\n",
            ],
            'no-reply' => [
                'Test payload',
                'subject',
                new HeaderBag(['one' => 'value']),
                null,
                "HPUB subject 24 36\r\nNATS/1.0\r\none: value\r\n\r\nTest payload\r\n",
            ],
            'empty-subject' => [
                'Test payload',
                '',
                new HeaderBag(['one' => 'value']),
                null,
                '',
                'Subject must be non-empty string.',
            ],
            'empty-reply' => [
                'Test payload',
                'subject',
                new HeaderBag(['one' => 'value']),
                '',
                '',
                'Reply-To must be non-empty string.',
            ],
            'empty-headers' => [
                'Test payload',
                'subject',
                new HeaderBag(),
                '',
                '',
                'Headers must not be empty.',
            ],
        ];
    }
}
