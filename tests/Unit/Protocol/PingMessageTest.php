<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\Metadata\PingMessage;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use PHPUnit\Framework\TestCase;

final class PingMessageTest extends TestCase
{
    public function testPayload(): void
    {
        $message = new PingMessage();
        self::assertSame("PING\r\n", (string)$message);
        self::assertSame(NatsMessageType::PING, $message->getType());
    }
}
