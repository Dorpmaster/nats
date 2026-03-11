<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol\Parser;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use Dorpmaster\Nats\Protocol\PongMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use PHPUnit\Framework\TestCase;

final class ParsePongMessageTest extends TestCase
{
    use AsyncTestTools;

    public function testMessage(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $isParsed = false;

            $callback = function (NatsProtocolMessageInterface $message) use (&$isParsed): void {
                self::assertInstanceOf(PongMessage::class, $message);

                $isParsed = true;
            };

            $parser = new ProtocolParser($callback);
            $parser->push("PONG\r\n");

            $parser->cancel();

            if ($isParsed !== true) {
                self::fail('The message has not been parsed');
            }
        });
    }

    public function testBrokenPayload(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $callback = function (NatsProtocolMessageInterface $message): void {
                self::assertInstanceOf(PongMessage::class, $message);
            };

            $parser = new ProtocolParser($callback);

            self::expectException(\RuntimeException::class);
            self::expectExceptionMessage('Unknown message type "{"server_id":"NABGL"');
            $parser->push('{"server_id":"NABGL' . NatsProtocolMessageInterface::DELIMITER);

            $parser->cancel();
        });
    }

    public function testWrongType(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $callback = function (NatsProtocolMessageInterface $message): void {
                self::assertInstanceOf(PongMessage::class, $message);
            };

            $parser = new ProtocolParser($callback);

            self::expectException(\RuntimeException::class);
            self::expectExceptionMessage('Unknown message type "TEST"');
            $parser->push('TEST test' . NatsProtocolMessageInterface::DELIMITER);

            $parser->cancel();
        });
    }
}
