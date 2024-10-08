<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol;

use Dorpmaster\Nats\Protocol\Contracts\ConnectMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ConnectInfo;

final readonly class ConnectMessage implements NatsProtocolMessageInterface, ConnectMessageInterface
{
    private string $payload;
    public function __construct(
        private ConnectInfo $connectInfo,
    ) {
        $options = [
            'verbose' => $this->connectInfo->verbose,
            'pedantic' => $this->connectInfo->pedantic,
            'tls_required' => $this->connectInfo->tls_required,
            'lang' => $this->connectInfo->lang,
            'version' => $this->connectInfo->version,
            'auth_token' => $this->connectInfo->auth_token,
            'user' => $this->connectInfo->user,
            'pass' => $this->connectInfo->pass,
            'name' => $this->connectInfo->name,
            'protocol' => $this->connectInfo->protocol,
            'echo' => $this->connectInfo->echo,
            'sig' => $this->connectInfo->sig,
            'jwt' => $this->connectInfo->jwt,
            'no_responders' => $this->connectInfo->no_responders,
            'headers' => $this->connectInfo->headers,
            'nkey' => $this->connectInfo->nkey,
        ];

        $this->payload = json_encode(
            array_filter($options, static fn(bool|int|string|null $item): bool => $item !== null)
        );
    }

    public function __toString(): string
    {
        return sprintf(
            '%s %s%s',
            $this->getType()->value,
            $this->payload,
            NatsProtocolMessageInterface::DELIMITER,
        );
    }

    public function getType(): NatsMessageType
    {
        return NatsMessageType::CONNECT;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getConnectInfo(): ConnectInfo
    {
        return $this->connectInfo;
    }
}
