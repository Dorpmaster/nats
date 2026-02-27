<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Dorpmaster\Nats\Domain\Client\SlowConsumerException;
use Dorpmaster\Nats\Domain\Client\MessageDispatcherInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionStorageInterface;
use Dorpmaster\Nats\Protocol\ConnectMessage;
use Dorpmaster\Nats\Protocol\Contracts\ConnectMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\InfoMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;
use Dorpmaster\Nats\Protocol\Metadata\ServerInfo;
use Dorpmaster\Nats\Protocol\NatsMessageType;
use Dorpmaster\Nats\Protocol\PongMessage;
use Psr\Log\LoggerInterface;

final class MessageDispatcher implements MessageDispatcherInterface
{
    private ServerInfo|null $serverInfo = null;
    /** @var array<string, int> */
    private array $pendingMessagesBySid = [];

    public function __construct(
        private readonly ConnectInfo $connectInfo,
        private readonly SubscriptionStorageInterface $storage,
        private readonly LoggerInterface|null $logger = null,
        private readonly int $maxPendingMessagesPerSubscription = 1000,
        private readonly SlowConsumerPolicy $slowConsumerPolicy = SlowConsumerPolicy::ERROR,
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

    public function getServerInfo(): ServerInfo|null
    {
        return $this->serverInfo;
    }

    private function processInfo(NatsProtocolMessageInterface $message): ConnectMessageInterface
    {
        assert($message instanceof InfoMessageInterface);

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

    private function processMsg(NatsProtocolMessageInterface $message): PubMessageInterface|HPubMessageInterface|null
    {
        assert(
            $message instanceof MsgMessageInterface
            || $message instanceof HMsgMessageInterface
        );

        $this->logger?->debug('Got the Msg Message', [
            'message' => $message,
        ]);

        $closure = $this->storage->get($message->getSid());
        if ($closure === null) {
            $this->logger?->warning('Could not find a subscription handler for the Message', [
                'message' => $message,
                'sid' => $message->getSid(),
            ]);

            return null;
        }

        $sid = $message->getSid();
        if (!$this->acquirePendingSlot($sid)) {
            return null;
        }

        try {
            $response = $closure($message);
        } finally {
            $this->releasePendingSlot($sid);
        }

        if ($response === null) {
            return null;
        }

        if (
            $response instanceof PubMessageInterface === true
            || $response instanceof HPubMessageInterface === true
        ) {
            return $response;
        }

        $this->logger?->error('Got a wrong response from the subscription handler for the Message', [
            'message' => $message,
            'sid' => $message->getSid(),
            'response' => $response,
        ]);

        return null;
    }

    private function acquirePendingSlot(string $sid): bool
    {
        $pending = ($this->pendingMessagesBySid[$sid] ?? 0) + 1;
        if ($pending > $this->maxPendingMessagesPerSubscription) {
            $policy = $this->slowConsumerPolicy;
            if ($policy === SlowConsumerPolicy::DROP_NEW) {
                $this->logger?->warning('Dropping message due to slow consumer (DROP_NEW policy)', [
                    'sid' => $sid,
                    'pending' => $pending,
                    'max_pending' => $this->maxPendingMessagesPerSubscription,
                ]);

                return false;
            }

            throw new SlowConsumerException(sprintf(
                'Slow consumer detected for sid "%s": pending=%d max=%d',
                $sid,
                $pending,
                $this->maxPendingMessagesPerSubscription,
            ));
        }

        $this->pendingMessagesBySid[$sid] = $pending;

        return true;
    }

    private function releasePendingSlot(string $sid): void
    {
        $pending = ($this->pendingMessagesBySid[$sid] ?? 1) - 1;
        if ($pending <= 0) {
            unset($this->pendingMessagesBySid[$sid]);

            return;
        }

        $this->pendingMessagesBySid[$sid] = $pending;
    }

    private function processErr(NatsProtocolMessageInterface $message): null
    {
        $this->logger?->error('Got the Err Message', [
            'message' => $message,
        ]);

        return null;
    }
}
