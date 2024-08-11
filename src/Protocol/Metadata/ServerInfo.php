<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Metadata;

final readonly class ServerInfo
{
    /**
     * @param string $server_id The unique identifier of the NATS server.
     * @param string $server_name The name of the NATS server.
     * @param string $version The version of NATS.
     * @param string $go The version of golang the NATS server was built with.
     * @param string $host The IP address used to start the NATS server.
     * @param string $port The port number the NATS server is configured to listen on.
     * @param bool $headers Whether the server supports headers.
     * @param int $max_payload Maximum payload size, in bytes, that the server will accept from the client.
     * @param int $proto An integer indicating the protocol version of the server.
     * @param string|null $client_id (optional) UINT64. The internal client identifier in the server.
     * @param bool|null $auth_required (optional) If this is true, then the client should try to authenticate
     * upon connect.
     * @param bool|null $tls_required (optional) If this is true, then the client must perform the TLS/1.2 handshake.
     * @param bool|null $tls_verify (optional) If this is true, the client must provide a valid certificate
     * during the TLS handshake.
     * @param bool|null $tls_available (optional) If this is true, the client can provide a valid certificate
     * during the TLS handshake.
     * @param array<non-empty-string>|null $connect_urls (optional) List of server urls that a client can connect to.
     * @param array<non-empty-string>|null $ws_connect_urls (optional) List of server urls that a websocket client
     * can connect to.
     * @param bool|null $ldm (optional) If the server supports Lame Duck Mode notifications, and the current
     * server has transitioned to lame duck, ldm will be set to true.
     * @param string|null $git_commit (optional) The git hash at which the NATS server was built.
     * @param bool|null $jetstream (optional) Whether the server supports JetStream.
     * @param string|null $ip (optional) The IP of the server.
     * @param string|null $client_ip (optional) The IP of the client.
     * @param string|null $nonce (optional) The nonce for use in CONNECT.
     * @param string|null $cluster (optional) The configured NATS domain of the server.
     */
    public function __construct(
        public string $server_id,
        public string $server_name,
        public string $version,
        public string $go,
        public string $host,
        public int $port,
        public bool $headers,
        public int $max_payload,
        public int $proto,
        public string|null $client_id = null,
        public bool|null $auth_required = null,
        public bool|null $tls_required = null,
        public bool|null $tls_verify = null,
        public bool|null $tls_available = null,
        public array|null $connect_urls = null,
        public array|null $ws_connect_urls = null,
        public bool|null $ldm = null,
        public string|null $git_commit = null,
        public bool|null $jetstream = null,
        public string|null $ip = null,
        public string|null $client_ip = null,
        public string|null $nonce = null,
        public string|null $cluster = null,
        public string|null $domain = null,
    )
    {
    }
}
