<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\ConnectMessage;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectMessageTest extends TestCase
{
    #[DataProvider('dataProvider')]
    public function testPayload(
        ConnectInfo $connectInfo,
        string $payload,
        string $toString,
    ): void {
        $message = new ConnectMessage($connectInfo);
        self::assertSame($toString, (string)$message);
        self::assertSame(NatsMessageType::CONNECT, $message->getType());
        self::assertSame($connectInfo, $message->getConnectInfo());
    }

    public static function dataProvider(): array
    {
        return [
            'full' => [
                new ConnectInfo(
                    verbose: false,
                    pedantic: false,
                    tls_required: false,
                    lang: 'php',
                    version: '8.3',
                    auth_token: 'token',
                    user: 'user',
                    pass: 'password',
                    name: 'test client',
                    protocol: 1,
                    echo: false,
                    sig: 'signature',
                    jwt: 'jwt',
                    no_responders: false,
                    headers: true,
                    nkey: 'key',
                ),
                '{"verbose":false,"pedantic":false,"tls_required":false,"lang":"php",""version":"8.3","auth_token":"token","user":"user","pass":"password","name":"test client","protocol":1,"echo":false,"sig":"signature","jwt":"jwt","no_responders":false,"headers":true,"nkey":"key"}',
                'CONNECT {"verbose":false,"pedantic":false,"tls_required":false,"lang":"php","version":"8.3","auth_token":"token","user":"user","pass":"password","name":"test client","protocol":1,"echo":false,"sig":"signature","jwt":"jwt","no_responders":false,"headers":true,"nkey":"key"}' . "\r\n",
            ],
            'only-required' => [
                new ConnectInfo(
                    verbose: false,
                    pedantic: false,
                    tls_required: false,
                    lang: 'php',
                    version: '8.3',
                ),
                '{"verbose":false,"pedantic":false,"tls_required":false,"lang":"php","version":"8.3"}',
                'CONNECT {"verbose":false,"pedantic":false,"tls_required":false,"lang":"php","version":"8.3"}' . "\r\n",
            ],
        ];
    }
}
