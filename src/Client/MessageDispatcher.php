<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Protocol\ConnectMessage;
use Dorpmaster\Nats\Protocol\Contracts\ConnectMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\InfoMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\Metadata\ServerInfo;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\PongMessage;
use PHPUnit\Logging\Exception;
use Psr\Log\LoggerInterface;

final class MessageDispatcher implements MessageDispatcherInterface
{
    private ServerInfo|null $serverInfo = null;

    public function __construct(
        private readonly ConnectInfo $connectInfo,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }


    public function dispatch(NatsProtocolMessageInterface $message): NatsProtocolMessageInterface|null
    {
        return match ($message->getType()) {
            NatsMessageType::INFO => $this->processInfo($message),
            NatsMessageType::MSG, NatsMessageType::HMSG => $this->processMsg($message),
            NatsMessageType::PING => new PongMessage(),
            NatsMessageType::ERR => $this->processErr($message),
            default => null,
        };
    }

    private function processInfo(InfoMessageInterface $message): ConnectMessageInterface
    {
        $this->logger?->debug('Got the Info Message', [
            'message' => $message,
        ]);

        $this->serverInfo = $message->getServerInfo();
        $this->logger?->debug('Set the Server Info', [
            'server_info' => $this->serverInfo,
        ]);

        $responseMessage = new ConnectMessage($this->connectInfo);
        $this->logger?->debug('Returning back the Connect Message', [
            'message' => $responseMessage,
        ]);

        return $responseMessage;
    }

    private function processMsg(MsgMessageInterface|HMsgMessageInterface $message): PubMessageInterface|HMsgMessageInterface|null
    {
        $this->logger?->debug('Got the Msg Message', [
            'message' => $message,
        ]);

        return null;
    }

    private function processErr(NatsProtocolMessageInterface $message): null
    {
        $this->logger?->error('Got the Err Message', [
            'message' => $message,
        ]);

        return null;
    }
}
