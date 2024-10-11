<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Dorpmaster\Nats\Client\MessageDispatcher;
use Dorpmaster\Nats\Protocol\Contracts\ConnectMessageInterface;
use Dorpmaster\Nats\Protocol\ErrMessage;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\HMsgMessage;
use Dorpmaster\Nats\Protocol\InfoMessage;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\Metadata\ServerInfo;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Protocol\OkMessage;
use Dorpmaster\Nats\Protocol\PingMessage;
use Dorpmaster\Nats\Protocol\PongMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MessageDispatcherTest extends TestCase
{
    public function testDispatchInfo(): void
    {
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createMock(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $logger);

        $info        = [
            'server_id' => 'id',
            'server_name' => 'nats',
            'version' => '1',
            'go' => 'go',
            'host' => 'host',
            'port' => 4321,
            'headers' => true,
            'max_payload' => 1234,
            'proto' => 1,
        ];
        $infoMessage = new InfoMessage(json_encode($info));

        $response = $dispatcher->dispatch($infoMessage);
        self::assertInstanceOf(ConnectMessageInterface::class, $response);
    }

    public function testGetServerInfo(): void
    {
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createMock(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $logger);

        $info = [
            'server_id' => 'id',
            'server_name' => 'nats',
            'version' => '1',
            'go' => 'go',
            'host' => 'host',
            'port' => 4321,
            'headers' => true,
            'max_payload' => 1234,
            'proto' => 1,
        ];

        self::assertNull($dispatcher->getServerInfo());

        $infoMessage = new InfoMessage(json_encode($info));
        $response    = $dispatcher->dispatch($infoMessage);
        self::assertInstanceOf(ConnectMessageInterface::class, $response);

        $serverInfo = $dispatcher->getServerInfo();
        self::assertInstanceOf(ServerInfo::class, $serverInfo);
        self::assertSame('id', $serverInfo->server_id);
        self::assertSame('nats', $serverInfo->server_name);
        self::assertSame('1', $serverInfo->version);
        self::assertSame('go', $serverInfo->go);
        self::assertSame('host', $serverInfo->host);
        self::assertSame(4321, $serverInfo->port);
        self::assertTrue($serverInfo->headers);
        self::assertSame(1234, $serverInfo->max_payload);
        self::assertSame(1, $serverInfo->proto);
    }

    public function testDispatchMsg(): void
    {
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createMock(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $logger);

        $message = new MsgMessage(
            'subject',
            'sid',
            'payload',
        );

        $response = $dispatcher->dispatch($message);
        self::assertNull($response);

//        $message = new MsgMessage(
//            'subject',
//            'sid',
//            'payload',
//            'reply'
//        );
//
//        $response = $dispatcher->dispatch($message);
//        self::assertInstanceOf(MsgMessageInterface::class, $response);
    }

    public function testDispatchHMsg(): void
    {
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createMock(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $logger);

        $message = new HMsgMessage(
            'subject',
            'sid',
            'payload',
            new HeaderBag(['a' => 'b']),
        );

        $response = $dispatcher->dispatch($message);
        self::assertNull($response);
//
//        $message = new HMsgMessage(
//            'subject',
//            'sid',
//            'payload',
//            new HeaderBag(),
//            'reply',
//        );
//
//        $response = $dispatcher->dispatch($message);
//        self::assertInstanceOf(HMsgMessageInterface::class, $response);
    }

    public function testDispatchPing(): void
    {
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createMock(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $logger);

        $message = new PingMessage();

        $response = $dispatcher->dispatch($message);
        self::assertInstanceOf(PongMessage::class, $response);
    }

    public function testDispatchOk(): void
    {
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createMock(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $logger);

        $message = new OkMessage();

        $response = $dispatcher->dispatch($message);
        self::assertNull($response);
    }

    public function testDispatchErr(): void
    {
        $connectInfo = new ConnectInfo(false, false, false, 'php', '8.3');
        $logger      = self::createMock(LoggerInterface::class);

        $dispatcher = new MessageDispatcher($connectInfo, $logger);

        $message = new ErrMessage('payload');

        $response = $dispatcher->dispatch($message);
        self::assertNull($response);
    }
}
