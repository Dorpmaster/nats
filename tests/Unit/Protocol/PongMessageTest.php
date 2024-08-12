<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\PongMessage;
use PHPUnit\Framework\TestCase;

final class PongMessageTest extends TestCase
{
    public function testPayload(): void
    {
        $message = new PongMessage();
        self::assertSame("PONG\r\n", (string)$message);
        self::assertSame(NatsMessageType::PONG, $message->getType());
    }
}
