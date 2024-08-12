<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\PubMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PubMessageTest extends TestCase
{
    #[DataProvider('dataProvider')]
    public function testPayload(
        string      $payload,
        string      $subject,
        string|null $replyTo,
        string      $toString,
    ): void
    {
        $message = new PubMessage(
            subject: $subject,
            payload: $payload,
            replyTo: $replyTo,
        );
        self::assertSame($toString, (string)$message);
        self::assertSame(NatsMessageType::PUB, $message->getType());
        self::assertSame($subject, $message->getSubject());
        self::assertSame($replyTo, $message->getReplyTo());
        self::assertEquals(strlen($payload), $message->getPayloadSize());
    }

    public static function dataProvider(): array
    {
        return [
            'with-reply' => [
                'Test payload',
                'subject.with.reply',
                'reply.to',
                "PUB subject.with.reply reply.to 12\r\nTest payload\r\n",
            ],
            'no-reply' => [
                'Test payload',
                'subject',
                null,
                "PUB subject 12\r\nTest payload\r\n",
            ],
        ];
    }
}
