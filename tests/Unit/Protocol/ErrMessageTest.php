<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\ErrMessage;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use PHPUnit\Framework\TestCase;

final class ErrMessageTest extends TestCase
{
    public function testPayload(): void
    {
        $message = new ErrMessage('Test');
        self::assertSame("-ERR Test\r\n", (string)$message);
        self::assertSame(NatsMessageType::ERR, $message->getType());
    }
}
