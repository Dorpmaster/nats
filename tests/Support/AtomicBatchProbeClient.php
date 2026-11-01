<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\Cancellation;
use Dorpmaster\Nats\Client\Client;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestClientInterface;
use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\Client\ServerInformationProviderInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;

/** Explicit wire evidence and barriers for real-server tests; never a production logger. */
final class AtomicBatchProbeClient implements ClientInterface, ConnectionBoundRequestClientInterface, ServerInformationProviderInterface
{
    public array $records = [];
    public array $checks  = [];
    public function __construct(public Client $inner, public \Closure|null $before = null, public \Closure|null $after = null)
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
            $index                            = count($this->checks);
            $this->checks[]                   = ['subject' => $message->getSubject(), 'payload' => $message->getPayload()];
            $response                         = $this->inner->requestOnceOnConnection($message, $connectionId, $timeout, $cancellation);
            $this->checks[$index]['response'] = $response->getPayload();
            return $response;
        }
        $index           = count($this->records);
        $actual          = $this->before?->__invoke($message, $index) ?? $message;
        $this->records[] = ['subject' => $actual->getSubject(), 'headers' => iterator_to_array($actual->getHeaders()), 'wire_base64' => base64_encode((string) $actual), 'payload_hex' => bin2hex($actual->getPayload())];
        try {
            $response                          = $this->inner->requestOnceOnConnection($actual, $connectionId, $timeout, $cancellation);
            $this->records[$index]['response'] = $response->getPayload();
            return $this->after?->__invoke($actual, $response, $index) ?? $response;
        } catch (\Throwable $error) {
            $this->records[$index]['error_type'] = $error::class;
            throw $error;
        }
    }
}
