<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MsgMessageTest extends TestCase
{
    #[DataProvider('dataProvider')]
    public function testPayload(
        string      $payload,
        string      $subject,
        string      $sid,
        string|null $replyTo,
        string      $toString,
        string|null $error = null,
    ): void
    {
        if ($error !== null) {
            self::expectException(InvalidArgumentException::class);
            self::expectExceptionMessage($error);
        }

        $message = new MsgMessage(
            subject: $subject,
            sid: $sid,
            payload: $payload,
            replyTo: $replyTo,
        );

        if ($error !== null) {
            return;
        }

        self::assertSame($toString, (string)$message);
        self::assertSame(NatsMessageType::MSG, $message->getType());
        self::assertSame($subject, $message->getSubject());
        self::assertSame($sid, $message->getSid());
        self::assertSame($replyTo, $message->getReplyTo());
        self::assertEquals(strlen($payload), $message->getPayloadSize());
    }

    public static function dataProvider(): array
    {
        return [
            'with-reply' => [
                'Test payload',
                'subject.with.reply',
                'a2',
                'reply.to',
                "MSG subject.with.reply a2 reply.to 12\r\nTest payload\r\n",
            ],
            'no-reply' => [
                'Test payload',
                'subject',
                'a2',
                null,
                "MSG subject a2 12\r\nTest payload\r\n",
            ],
            'subject-with-dot-star' => [
                'Test payload',
                'subject.test.*',
                'a2',
                null,
                "MSG subject.test.* a2 12\r\nTest payload\r\n",
            ],
            'subject-with-wildcard' => [
                'Test payload',
                'subject.test.>',
                'a2',
                null,
                "MSG subject.test.> a2 12\r\nTest payload\r\n",
            ],
            'empty-subject' => [
                'Test payload',
                '',
                'a2',
                null,
                '',
                'Invalid Subject: ',
            ],
            'wrong-subject-whitespaces' => [
                'Test payload',
                'test. test',
                'a2',
                null,
                '',
                'Invalid Subject: test. test',
            ],
            'wrong-subject-double-dot' => [
                'Test payload',
                'test..test',
                'a2',
                null,
                '',
                'Invalid Subject: test..test',
            ],
            'wrong-subject-double-star' => [
                'Test payload',
                'test.**test',
                'a2',
                null,
                '',
                'Invalid Subject: test.**test',
            ],
            'wrong-subject-middle-star' => [
                'Test payload',
                'test*test',
                'a2',
                null,
                '',
                'Invalid Subject: test*test',
            ],
            'wrong-subject-wildcard' => [
                'Test payload',
                'test.te>st',
                'a2',
                null,
                '',
                'Invalid Subject: test.te>st',
            ],
            'empty-reply' => [
                'Test payload',
                'subject',
                'a2',
                '',
                '',
                'Invalid Reply-To: ',
            ],
            'wrong-reply-whitespaces' => [
                'Test payload',
                'subject',
                'a2',
                'test. test',
                '',
                'Invalid Reply-To: test. test',
            ],
            'wrong-reply-double-dot' => [
                'Test payload',
                'subject',
                'a2',
                'test..test',
                '',
                'Invalid Reply-To: test..test',
            ],
            'wrong-reply-double-star' => [
                'Test payload',
                'subject',
                'a2',
                'test.**test',
                '',
                'Invalid Reply-To: test.**test',
            ],
            'wrong-reply-middle-star' => [
                'Test payload',
                'subject',
                'a2',
                'test*test',
                '',
                'Invalid Reply-To: test*test',
            ],
            'wrong-reply-wildcard' => [
                'Test payload',
                'subject',
                'a2',
                'test.te>st',
                '',
                'Invalid Reply-To: test.te>st',
            ],
            'empty-sid' => [
                'Test payload',
                'subject',
                '',
                null,
                '',
                'Invalid SID: ',
            ],
            'wrong-sid-whitespaces' => [
                'Test payload',
                'subject',
                'sid 9',
                null,
                '',
                'Invalid SID: sid 9',
            ],
            'wrong-sid-not-alnum' => [
                'Test payload',
                'subject',
                'sid&%$9',
                null,
                '',
                'Invalid SID: sid&%$9',
            ],
        ];
    }
}
