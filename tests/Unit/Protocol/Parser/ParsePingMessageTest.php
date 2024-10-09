<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol\Parser;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Tests\AsyncTestCase;

final class ParsePingMessageTest extends AsyncTestCase
{
    public function testMessage(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $isParsed = false;

            $callback = function (NatsProtocolMessageInterface $message) use (&$isParsed): void {
                self::assertInstanceOf(PingMessage::class, $message);

                $isParsed = true;
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield "PING\r\n";
            };

            foreach ($source() as $chunk) {
                $parser->push($chunk);
            }

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
                self::assertInstanceOf(PingMessage::class, $message);
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield '{"server_id":"NABGL' . NatsProtocolMessageInterface::DELIMITER;
            };

            self::expectException(\RuntimeException::class);
            self::expectExceptionMessage('Unknown message type "{"server_id":"NABGL"');
            foreach ($source() as $chunk) {
                $parser->push($chunk);
            }

            $parser->cancel();
        });
    }

    public function testWrongType(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $callback = function (NatsProtocolMessageInterface $message): void {
                self::assertInstanceOf(PingMessage::class, $message);
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield 'TEST test' . NatsProtocolMessageInterface::DELIMITER;
            };

            self::expectException(\RuntimeException::class);
            self::expectExceptionMessage('Unknown message type "TEST"');
            foreach ($source() as $chunk) {
                $parser->push($chunk);
            }

            $parser->cancel();
        });
    }
}
