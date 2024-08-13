<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\PubMessage;
use InvalidArgumentException;
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
        string|null $error = null,
    ): void
    {
        if ($error !== null) {
            self::expectException(InvalidArgumentException::class);
            self::expectExceptionMessage($error);
        }

        $message = new PubMessage(
            subject: $subject,
            payload: $payload,
            replyTo: $replyTo,
        );

        if ($error !== null) {
            return;
        }

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
            'subject-with-dot-star' => [
                'Test payload',
                'subject.test.*',
                null,
                "PUB subject.test.* 12\r\nTest payload\r\n",
            ],
            'subject-with-wildcard' => [
                'Test payload',
                'subject.test.>',
                null,
                "PUB subject.test.> 12\r\nTest payload\r\n",
            ],
            'empty-subject' => [
                'Test payload',
                '',
                null,
                '',
                'Invalid Subject: ',
            ],
            'wrong-subject-whitespaces' => [
                'Test payload',
                'test. test',
                null,
                '',
                'Invalid Subject: test. test',
            ],
            'wrong-subject-double-dot' => [
                'Test payload',
                'test..test',
                null,
                '',
                'Invalid Subject: test..test',
            ],
            'wrong-subject-double-star' => [
                'Test payload',
                'test.**test',
                null,
                '',
                'Invalid Subject: test.**test',
            ],
            'wrong-subject-middle-star' => [
                'Test payload',
                'test*test',
                null,
                '',
                'Invalid Subject: test*test',
            ],
            'wrong-subject-wildcard' => [
                'Test payload',
                'test.te>st',
                null,
                '',
                'Invalid Subject: test.te>st',
            ],
            'empty-reply' => [
                'Test payload',
                'subject',
                '',
                '',
                'Invalid Reply-To: ',
            ],
            'wrong-reply-whitespaces' => [
                'Test payload',
                'subject',
                'test. test',
                '',
                'Invalid Reply-To: test. test',
            ],
            'wrong-reply-double-dot' => [
                'Test payload',
                'subject',
                'test..test',
                '',
                'Invalid Reply-To: test..test',
            ],
            'wrong-reply-double-star' => [
                'Test payload',
                'subject',
                'test.**test',
                '',
                'Invalid Reply-To: test.**test',
            ],
            'wrong-reply-middle-star' => [
                'Test payload',
                'subject',
                'test*test',
                '',
                'Invalid Reply-To: test*test',
            ],
            'wrong-reply-wildcard' => [
                'Test payload',
                'subject',
                'test.te>st',
                '',
                'Invalid Reply-To: test.te>st',
            ],
        ];
    }
}
