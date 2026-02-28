<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Protocol;

use Dorpmaster\Nats\Protocol\InfoMessage;
use Dorpmaster\Nats\Protocol\Metadata\ServerInfo;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InfoMessageTest extends TestCase
{
    #[DataProvider('dataProvider')]
    public function testPayload(
        string $payload,
        string $toString,
        string|null $exception = null
    ): void {
        if ($exception !== null) {
            self::expectException(InvalidArgumentException::class);
            self::expectExceptionMessage($exception);
        }

        $message = new InfoMessage($payload);
        if ($exception === null) {
            self::assertSame($toString, (string)$message);
            self::assertSame(NatsMessageType::INFO, $message->getType());
            self::assertInstanceOf(ServerInfo::class, $message->getServerInfo());
        }
    }

    public static function dataProvider(): array
    {
        return [
            'full' => [
                '{"server_id":"NBYQVR2I5KUG5I6FN2YNFAPP5GVWF6V7QYGFK2VSKQDOY5ZYC2Y7QAZO","server_name":"us-south-nats-demo","version":"2.10.18","proto":1,"git_commit":"57d23ac","go":"go1.22.5","host":"0.0.0.0","port":4222,"headers":true,"tls_available":true,"max_payload":1048576,"jetstream":true,"client_id":793982,"client_ip":"94.67.65.133","nonce":"KHC0jVIt4_Na7XI","xkey":"XBSKODUWHKUXOLWU6EUGS2UXHOVOGBYQJ322OJ4DS4PB72HQRGEU5T7U"}',
                'INFO {"server_id":"NBYQVR2I5KUG5I6FN2YNFAPP5GVWF6V7QYGFK2VSKQDOY5ZYC2Y7QAZO","server_name":"us-south-nats-demo","version":"2.10.18","proto":1,"git_commit":"57d23ac","go":"go1.22.5","host":"0.0.0.0","port":4222,"headers":true,"tls_available":true,"max_payload":1048576,"jetstream":true,"client_id":793982,"client_ip":"94.67.65.133","nonce":"KHC0jVIt4_Na7XI","xkey":"XBSKODUWHKUXOLWU6EUGS2UXHOVOGBYQJ322OJ4DS4PB72HQRGEU5T7U"}' . "\r\n",
            ],
            'only-required' => [
                '{"server_id":"NBYQVR2I5KUG5I6FN2YNFAPP5GVWF6V7QYGFK2VSKQDOY5ZYC2Y7QAZO","server_name":"us-south-nats-demo","version":"2.10.18","proto":1,"go":"go1.22.5","host":"0.0.0.0","port":4222,"headers":true,"max_payload":1048576}',
                'INFO {"server_id":"NBYQVR2I5KUG5I6FN2YNFAPP5GVWF6V7QYGFK2VSKQDOY5ZYC2Y7QAZO","server_name":"us-south-nats-demo","version":"2.10.18","proto":1,"go":"go1.22.5","host":"0.0.0.0","port":4222,"headers":true,"max_payload":1048576}' . "\r\n",
            ],
            'missed-server_id' => [
                '{"server_name":"us-south-nats-demo","version":"2.10.18","proto":1,"go":"go1.22.5","host":"0.0.0.0","port":4222,"headers":true,"max_payload":1048576}',
                '',
                'Missed required option: server_id',
            ],
            'missed-server_name' => [
                '{"server_id":"NBYQVR2I5KUG5I6FN2YNFAPP5GVWF6V7QYGFK2VSKQDOY5ZYC2Y7QAZO","version":"2.10.18","proto":1,"go":"go1.22.5","host":"0.0.0.0","port":4222,"headers":true,"max_payload":1048576}',
                '',
                'Missed required option: server_name',
            ],
            'missed-version' => [
                '{"server_id":"NBYQVR2I5KUG5I6FN2YNFAPP5GVWF6V7QYGFK2VSKQDOY5ZYC2Y7QAZO","server_name":"us-south-nats-demo","proto":1,"go":"go1.22.5","host":"0.0.0.0","port":4222,"headers":true,"max_payload":1048576}',
                '',
                'Missed required option: version',
            ],
            'missed-go' => [
                '{"server_id":"NBYQVR2I5KUG5I6FN2YNFAPP5GVWF6V7QYGFK2VSKQDOY5ZYC2Y7QAZO","server_name":"us-south-nats-demo","version":"2.10.18","proto":1,"host":"0.0.0.0","port":4222,"headers":true,"max_payload":1048576}',
                '',
                'Missed required option: go',
            ],
            'missed-host' => [
                '{"server_id":"NBYQVR2I5KUG5I6FN2YNFAPP5GVWF6V7QYGFK2VSKQDOY5ZYC2Y7QAZO","server_name":"us-south-nats-demo","version":"2.10.18","proto":1,"go":"go1.22.5","port":4222,"headers":true,"max_payload":1048576}',
                '',
                'Missed required option: host',
            ],
            'missed-port' => [
                '{"server_id":"NBYQVR2I5KUG5I6FN2YNFAPP5GVWF6V7QYGFK2VSKQDOY5ZYC2Y7QAZO","server_name":"us-south-nats-demo","version":"2.10.18","proto":1,"go":"go1.22.5","host":"0.0.0.0","headers":true,"max_payload":1048576}',
                '',
                'Missed required option: port',
            ],
            'missed-headers' => [
                '{"server_id":"NBYQVR2I5KUG5I6FN2YNFAPP5GVWF6V7QYGFK2VSKQDOY5ZYC2Y7QAZO","server_name":"us-south-nats-demo","version":"2.10.18","proto":1,"go":"go1.22.5","host":"0.0.0.0","port":4222,"max_payload":1048576}',
                '',
                'Missed required option: headers',
            ],
            'missed-max_payload' => [
                '{"server_id":"NBYQVR2I5KUG5I6FN2YNFAPP5GVWF6V7QYGFK2VSKQDOY5ZYC2Y7QAZO","server_name":"us-south-nats-demo","version":"2.10.18","proto":1,"go":"go1.22.5","host":"0.0.0.0","port":4222,"headers":true}',
                '',
                'Missed required option: max_payload',
            ],
            'missed-proto' => [
                '{"server_id":"NBYQVR2I5KUG5I6FN2YNFAPP5GVWF6V7QYGFK2VSKQDOY5ZYC2Y7QAZO","server_name":"us-south-nats-demo","version":"2.10.18","go":"go1.22.5","host":"0.0.0.0","port":4222,"headers":true,"max_payload":1048576}',
                '',
                'Missed required option: proto',
            ],
        ];
    }
}
