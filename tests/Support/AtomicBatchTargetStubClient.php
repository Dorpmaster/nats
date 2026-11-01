<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\Cancellation;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestClientInterface;
use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\Client\ServerInformationProviderInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;

/** Canned read-only target discovery; batch requests still reach the test's mock writer. */
final class AtomicBatchTargetStubClient implements ClientInterface, ConnectionBoundRequestClientInterface, ServerInformationProviderInterface
{
    public array $checks = [];
    public function __construct(public ClientInterface&ConnectionBoundRequestClientInterface&ServerInformationProviderInterface $inner, public \Closure|null $read = null)
    {
    }
    public function connect(): void
    {
        $this->inner->connect();
    }
    public function disconnect(): void
    {
        $this->inner->disconnect();
    }
    public function waitForTermination(): void
    {
        $this->inner->waitForTermination();
    }
    public function subscribe(string $subject, \Closure $closure, string|null $queueGroup = null): string
    {
        return $this->inner->subscribe($subject, $closure, $queueGroup);
    }
    public function unsubscribe(string $sid): void
    {
        $this->inner->unsubscribe($sid);
    }
    public function publish(PubMessageInterface|HPubMessageInterface $message): void
    {
        throw new \LogicException('Atomic publish must not use ordinary publication');
    }
    public function request(PubMessageInterface|HPubMessageInterface $message, float $timeout = 30): MsgMessageInterface|HMsgMessageInterface
    {
        throw new \LogicException('Atomic publish must not use replayable requests');
    }
    public function requestOnce(PubMessageInterface|HPubMessageInterface $message, float $timeout = 30): MsgMessageInterface|HMsgMessageInterface
    {
        return $this->inner->requestOnce($message, $timeout);
    }
    public function getServerInformation(): ServerInformation
    {
        return $this->inner->getServerInformation();
    }
    public function requestOnceOnConnection(PubMessageInterface|HPubMessageInterface $message, int $connectionId, float $timeout = 30, Cancellation|null $cancellation = null): MsgMessageInterface|HMsgMessageInterface
    {
        if (!$message instanceof HPubMessageInterface) {
            $subject        = $message->getSubject();
            $this->checks[] = $subject;
            $payload        = $this->read?->__invoke($message) ?? AtomicBatchTopologyResponses::payload($subject, $this->inner->getServerInformation()->serverId) ?? (str_starts_with($subject, '$JS.API.STREAM.INFO.')
                ? '{"type":"io.nats.jetstream.api.v1.stream_info_response","config":{"name":"ORDERS","allow_atomic":true,"metadata":{"_nats.level":"5"}}}'
                : '{"type":"io.nats.jetstream.api.v1.stream_names_response","total":1,"streams":["ORDERS"]}');
            return new \Dorpmaster\Nats\Protocol\MsgMessage('ack', '1', $payload);
        }
        return $this->inner->requestOnceOnConnection($message, $connectionId, $timeout, $cancellation);
    }
}
