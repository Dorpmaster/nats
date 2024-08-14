<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HMsgMessage;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HMsgMessageTest extends TestCase
{
    #[DataProvider('dataProvider')]
    public function testPayload(
        string      $payload,
        string      $subject,
        string      $sid,
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

        $message = new HMsgMessage(
            subject: $subject,
            sid: $sid,
            payload: $payload,
            headers: $headers,
            replyTo: $replyTo,
        );

        if ($error !== null) {
            return;
        }

        self::assertSame($toString, (string)$message);
        self::assertSame(NatsMessageType::HMSG, $message->getType());
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
                new HeaderBag(['one' => 'value']),
                'reply.to',
                "HMSG subject.with.reply a2 reply.to 24 36\r\nNATS/1.0\r\none: value\r\n\r\nTest payload\r\n",
            ],
            'no-reply' => [
                'Test payload',
                'subject',
                'a2',
                new HeaderBag(['one' => 'value']),
                null,
                "HMSG subject a2 24 36\r\nNATS/1.0\r\none: value\r\n\r\nTest payload\r\n",
            ],
            'subject-with-dot-star' => [
                'Test payload',
                'subject.test.*',
                'a2',
                new HeaderBag(['one' => 'value']),
                null,
                "HMSG subject.test.* a2 24 36\r\nNATS/1.0\r\none: value\r\n\r\nTest payload\r\n",
            ],
            'subject-with-wildcard' => [
                'Test payload',
                'subject.test.>',
                'a2',
                new HeaderBag(['one' => 'value']),
                null,
                "HMSG subject.test.> a2 24 36\r\nNATS/1.0\r\none: value\r\n\r\nTest payload\r\n",
            ],
            'empty-subject' => [
                'Test payload',
                '',
                'a2',
                new HeaderBag(['one' => 'value']),
                null,
                '',
                'Invalid Subject: ',
            ],
            'wrong-subject-whitespaces' => [
                'Test payload',
                'test. test',
                'a2',
                new HeaderBag(['one' => 'value']),
                null,
                '',
                'Invalid Subject: test. test',
            ],
            'wrong-subject-double-dot' => [
                'Test payload',
                'test..test',
                'a2',
                new HeaderBag(['one' => 'value']),
                null,
                '',
                'Invalid Subject: test..test',
            ],
            'wrong-subject-double-star' => [
                'Test payload',
                'test.**test',
                'a2',
                new HeaderBag(['one' => 'value']),
                null,
                '',
                'Invalid Subject: test.**test',
            ],
            'wrong-subject-middle-star' => [
                'Test payload',
                'test*test',
                'a2',
                new HeaderBag(['one' => 'value']),
                null,
                '',
                'Invalid Subject: test*test',
            ],
            'wrong-subject-wildcard' => [
                'Test payload',
                'test.te>st',
                'a2',
                new HeaderBag(['one' => 'value']),
                null,
                '',
                'Invalid Subject: test.te>st',
            ],
            'empty-reply' => [
                'Test payload',
                'subject',
                'a2',
                new HeaderBag(['one' => 'value']),
                '',
                '',
                'Invalid Reply-To: ',
            ],
            'wrong-reply-whitespaces' => [
                'Test payload',
                'subject',
                'a2',
                new HeaderBag(['one' => 'value']),
                'test. test',
                '',
                'Invalid Reply-To: test. test',
            ],
            'wrong-reply-double-dot' => [
                'Test payload',
                'subject',
                'a2',
                new HeaderBag(['one' => 'value']),
                'test..test',
                '',
                'Invalid Reply-To: test..test',
            ],
            'wrong-reply-double-star' => [
                'Test payload',
                'subject',
                'a2',
                new HeaderBag(['one' => 'value']),
                'test.**test',
                '',
                'Invalid Reply-To: test.**test',
            ],
            'wrong-reply-middle-star' => [
                'Test payload',
                'subject',
                'a2',
                new HeaderBag(['one' => 'value']),
                'test*test',
                '',
                'Invalid Reply-To: test*test',
            ],
            'wrong-reply-wildcard' => [
                'Test payload',
                'subject',
                'a2',
                new HeaderBag(['one' => 'value']),
                'test.te>st',
                '',
                'Invalid Reply-To: test.te>st',
            ],
            'empty-sid' => [
                'Test payload',
                'subject',
                '',
                new HeaderBag(['one' => 'value']),
                null,
                '',
                'Invalid SID: ',
            ],
            'wrong-sid-whitespaces' => [
                'Test payload',
                'subject',
                'sid 9',
                new HeaderBag(['one' => 'value']),
                null,
                '',
                'Invalid SID: sid 9',
            ],
            'wrong-sid-not-alnum' => [
                'Test payload',
                'subject',
                'sid&%$9',
                new HeaderBag(['one' => 'value']),
                null,
                '',
                'Invalid SID: sid&%$9',
            ],
            'empty-headers' => [
                'Test payload',
                'subject',
                'a2',
                new HeaderBag(),
                '',
                '',
                'Headers must not be empty',
            ],
        ];
    }
}
