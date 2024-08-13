<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\UnSubMessage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnSubMessageTest extends TestCase
{
    #[DataProvider('dataProvider')]
    public function testPayload(
        string      $sid,
        int|null $max,
        string      $toString,
        string|null $error = null,
    ): void
    {
        if ($error !== null) {
            self::expectException(InvalidArgumentException::class);
            self::expectExceptionMessage($error);
        }

        $message = new UnSubMessage(
            sid: $sid,
            maxMessages: $max,
        );

        if ($error !== null) {
            return;
        }

        self::assertSame($toString, (string)$message);
        self::assertSame(NatsMessageType::UNSUB, $message->getType());
        self::assertSame($sid, $message->getSid());
        self::assertSame($max, $message->getMaxMessages());
    }

    public static function dataProvider(): array
    {
        return [
            'with-max' => [
                'sid5',
                5,
                "UNSUB sid5 5\r\n",
            ],
            'no-nax' => [
                'sid9',
                null,
                "UNSUB sid9\r\n",
            ],
            'empty-sid' => [
                '',
                null,
                '',
                'Invalid SID: ',
            ],
            'wrong-sid-whitespaces' => [
                'sid 9',
                null,
                '',
                'Invalid SID: sid 9',
            ],
            'wrong-sid-not-alnum' => [
                'sid&%$9',
                null,
                '',
                'Invalid SID: sid&%$9',
            ],
            'zero-max' => [
                'sid9',
                0,
                '',
                'Max Messages should be greater then 0',
            ],
            'negative-max' => [
                'sid9',
                -5,
                '',
                'Max Messages should be greater then 0',
            ],
        ];
    }
}
