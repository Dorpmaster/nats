<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\PubMessage;
use Dorpmaster\Nats\Protocol\SubMessage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubMessageTest extends TestCase
{
    #[DataProvider('dataProvider')]
    public function testPayload(
        string      $subject,
        string      $sid,
        string|null $queueGroup,
        string      $toString,
        string|null $error = null,
    ): void
    {
        if ($error !== null) {
            self::expectException(InvalidArgumentException::class);
            self::expectExceptionMessage($error);
        }

        $message = new SubMessage(
            subject: $subject,
            sid: $sid,
            queueGroup: $queueGroup,
        );

        if ($error !== null) {
            return;
        }

        self::assertSame($toString, (string)$message);
        self::assertSame(NatsMessageType::SUB, $message->getType());
        self::assertSame($subject, $message->getSubject());
        self::assertSame($sid, $message->getSid());
        self::assertSame($queueGroup, $message->getQueueGroup());
    }

    public static function dataProvider(): array
    {
        return [
            'with-queue-group' => [
                'subject',
                'sid5',
                'a2s3d4',
                "SUB subject a2s3d4 sid5\r\n",
            ],
            'no-queue-group' => [
                'subject',
                'sid9',
                null,
                "SUB subject sid9\r\n",
            ],
            'subject-with-dot-star' => [
                'subject.test.*',
                'sid9',
                null,
                "SUB subject.test.* sid9\r\n",
            ],
            'subject-with-wildcard' => [
                'subject.test.>',
                'sid9',
                null,
                "SUB subject.test.> sid9\r\n",
            ],
            'empty-subject' => [
                '',
                'sid9',
                null,
                '',
                'Invalid Subject: ',
            ],
            'wrong-subject-whitespaces' => [
                'test. test',
                'sid9',
                null,
                '',
                'Invalid Subject: test. test',
            ],
            'wrong-subject-double-dot' => [
                'test..test',
                'sid9',
                null,
                '',
                'Invalid Subject: test..test',
            ],
            'wrong-subject-double-star' => [
                'test.**test',
                'sid9',
                null,
                '',
                'Invalid Subject: test.**test',
            ],
            'wrong-subject-middle-star' => [
                'test*test',
                'sid9',
                null,
                '',
                'Invalid Subject: test*test',
            ],
            'wrong-subject-wildcard' => [
                'test.te>st',
                'sid9',
                null,
                '',
                'Invalid Subject: test.te>st',
            ],
            'empty-sid' => [
                'subject',
                '',
                'a2',
                '',
                'Invalid SID: ',
            ],
            'wrong-sid-whitespaces' => [
                'subject',
                'sid 9',
                'a2',
                '',
                'Invalid SID: sid 9',
            ],
            'wrong-sid-not-alnum' => [
                'subject',
                'sid&%$9',
                'a2',
                '',
                'Invalid SID: sid&%$9',
            ],
            'empty-queue-group' => [
                'subject',
                'sid9',
                '',
                '',
                'Invalid Queue Group: ',
            ],
            'wrong-queue-group-whitespaces' => [
                'subject',
                'sid9',
                'test test',
                '',
                'Invalid Queue Group: test test',
            ],
            'wrong-queue-group-not-alnum' => [
                'subject',
                'sid9',
                'test..test',
                '',
                'Invalid Queue Group: test..test',
            ],
        ];
    }
}
