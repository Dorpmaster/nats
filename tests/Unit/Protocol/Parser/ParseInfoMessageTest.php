<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol\Parser;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\InfoMessage;
use Dorpmaster\Nats\Protocol\Metadata\ServerInfo;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use Dorpmaster\Nats\Tests\AsyncTestCase;

final class ParseInfoMessageTest extends AsyncTestCase
{
    public function testMessage(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            $isParsed = false;

            $callback = function (NatsProtocolMessageInterface $message) use (&$isParsed): void {
                self::assertInstanceOf(InfoMessage::class, $message);

                /** @var ServerInfo $serverInfo */
                $serverInfo = $message->getServerInfo();

                self::assertSame(
                    'NABGLAPI2AJEVYIPG54HIEUXZ5O7PEM5ZV5RFAJAECKG6T4BUPMWXLHY',
                    $serverInfo->server_id
                );
                self::assertSame('us-south-nats-demo', $serverInfo->server_name);
                self::assertSame('2.10.20', $serverInfo->version);
                self::assertSame(1, $serverInfo->proto);
                self::assertSame('7140387', $serverInfo->git_commit);
                self::assertSame('go1.22.6', $serverInfo->go);
                self::assertSame('0.0.0.0', $serverInfo->host);
                self::assertSame(4222, $serverInfo->port);
                self::assertTrue($serverInfo->headers);
                self::assertTrue($serverInfo->tls_available);
                self::assertSame(1048576, $serverInfo->max_payload);
                self::assertTrue($serverInfo->jetstream);
                self::assertSame('450394', $serverInfo->client_id);
                self::assertSame('94.67.65.87', $serverInfo->client_ip);
                self::assertSame('FP8SuWGPDUB73U4', $serverInfo->nonce);

                $isParsed = true;
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield 'INFO {"server_id":"NABGL';
                yield 'API2AJEVYIPG54HIEUXZ5O7PEM5ZV5RFAJAECKG6T4BUPMWXLHY","server_name":"us-south';
                yield '-nats-demo","version":"2.10.20","proto":1,"git_commit":"7140387","go":"go1.22.6",';
                yield '"host":"0.0.0.0","port":4222,"headers":true,"tls_available":true,"max_payload":1048576,';
                yield '"jetstream":true,"client_id":450394,"client_ip":"94.67.65.87","nonce":"FP8SuWGPDUB73U4",';
                yield '"xkey":"XBERSHAJDGJM3KTAXZEFG7FNJ45ABPXF5IS2OHOKS3FHXADSY65XEQIV"}' . "\r\n";
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
                self::assertInstanceOf(InfoMessage::class, $message);
            };

            $parser = new ProtocolParser($callback);

            $source = function (): \Generator {
                yield 'INFO {"server_id":"NABGL' . NatsProtocolMessageInterface::DELIMITER;
            };

            self::expectException(\JsonException::class);
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
                self::assertInstanceOf(InfoMessage::class, $message);
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
