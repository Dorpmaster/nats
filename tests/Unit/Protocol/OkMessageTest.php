<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\OkMessage;
use PHPUnit\Framework\TestCase;

final class OkMessageTest extends TestCase
{
    public function testPayload(): void
    {
        $message = new OkMessage();
        self::assertSame("+OK\r\n", (string)$message);
        self::assertSame(NatsMessageType::OK, $message->getType());
    }
}
