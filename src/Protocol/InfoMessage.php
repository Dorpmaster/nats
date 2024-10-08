<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol;

use Dorpmaster\Nats\Protocol\Contracts\InfoMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Metadata\ServerInfo;
use InvalidArgumentException;

final readonly class InfoMessage implements NatsProtocolMessageInterface, InfoMessageInterface
{
    private ServerInfo $serverInfo;
    public function __construct(
        private string $payload,
    ) {
        $options = json_decode($this->payload, true, 512, JSON_THROW_ON_ERROR);

        $this->serverInfo = new ServerInfo(
            server_id: $options['server_id'] ??
                throw new InvalidArgumentException('Missed required option: server_id'),
            server_name: $options['server_name'] ??
                throw new InvalidArgumentException('Missed required option: server_name'),
            version: $options['version'] ??
                throw new InvalidArgumentException('Missed required option: version'),
            go: $options['go'] ??
                throw new InvalidArgumentException('Missed required option: go'),
            host: $options['host'] ??
                throw new InvalidArgumentException('Missed required option: host'),
            port: $options['port'] ??
                throw new InvalidArgumentException('Missed required option: port'),
            headers: $options['headers'] ??
                throw new InvalidArgumentException('Missed required option: headers'),
            max_payload: $options['max_payload'] ??
                throw new InvalidArgumentException('Missed required option: max_payload'),
            proto: $options['proto' ] ??
                throw new InvalidArgumentException('Missed required option: proto'),
            client_id: isset($options['client_id']) ? (string) $options['client_id'] : null,
            auth_required: $options['auth_required'] ?? null,
            tls_required: $options['tls_required'] ?? null,
            tls_verify: $options['tls_verify'] ?? null,
            tls_available: $options['tls_available'] ?? null,
            connect_urls: $options['connect_urls'] ?? null,
            ws_connect_urls: $options['ws_connect_urls'] ?? null,
            ldm: $options['ldm'] ?? null,
            git_commit: $options['git_commit'] ?? null,
            jetstream: $options['jetstream'] ?? null,
            ip: $options['ip'] ?? null,
            client_ip: $options['client_ip'] ?? null,
            nonce: $options['nonce'] ?? null,
            cluster: $options['cluster'] ?? null,
            domain: $options['domain'] ?? null,
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
        return NatsMessageType::INFO;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getServerInfo(): ServerInfo
    {
        return $this->serverInfo;
    }
}
