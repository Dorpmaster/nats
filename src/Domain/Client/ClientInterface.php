<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Amp\CancelledException;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;

interface ClientInterface
{
    /**
     * @throws CancelledException
     * @throws ConnectionException
     */
    public function connect(): void;

    /**
     * @throws CancelledException
     * @throws ConnectionException
     */
    public function disconnect(): void;

    public function waitForTermination(): void;

    public function subscribe(string $subject, \Closure $closure): string;

    public function unsubscribe(string $sid): void;

    public function publish(PubMessageInterface|HPubMessageInterface $message): void;

    public function request(
        PubMessageInterface|HPubMessageInterface $message,
        float                                    $timeout = 30
    ): MsgMessageInterface|HMsgMessageInterface;
}
