<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Metadata;

final readonly class ConnectInfo
{
    /**
     * @param bool $verbose Turns on +OK protocol acknowledgements.
     * @param bool $pedantic Turns on additional strict format checking, e.g. for properly formed subjects.
     * @param bool $tls_required Indicates whether the client requires an SSL connection.
     * @param string $lang The implementation language of the client.
     * @param string $version The version of the client.
     * @param string|null $auth_token (optional) Client authorization token.
     * @param string|null $user (optional) Connection username.
     * @param string|null $pass (optional) Connection password.
     * @param string|null $name (optional) Client name.
     * @param int|null $protocol (optional) Sending 0 (or absent) indicates client supports original protocol.
     * Sending 1 indicates that the client supports dynamic reconfiguration of cluster topology changes
     * by asynchronously receiving INFO messages with known servers it can reconnect to.
     * @param bool|null $echo (optional) If set to false, the server (version 1.2.0+) will not send
     * originating messages from this connection to its own subscriptions. Clients should set this
     * to false only for server supporting this feature, which is when proto in the INFO protocol is set to at least 1.
     * @param string|null $sig (optional) In case the server has responded with a nonce on INFO,
     * then a NATS client must use this field to reply with the signed nonce.
     * @param string|null $jwt (optional) The JWT that identifies a user permissions and account.
     * @param bool|null $no_responders (optional) Enable quick replies for cases where a request is sent to
     * a topic with no responders.
     * @param bool|null $headers (optional) Whether the client supports headers.
     * @param string|null $nkey (optional) The public NKey to authenticate the client. This will be used
     * to verify the signature (sig) against the nonce provided in the INFO message..
     */
    public function __construct(
        public bool $verbose,
        public bool $pedantic,
        public bool $tls_required,
        public string $lang,
        public string $version,
        public string|null $auth_token = null,
        public string|null $user = null,
        public string|null $pass = null,
        public string|null $name = null,
        public int|null $protocol = null,
        public bool|null $echo = null,
        public string|null $sig = null,
        public string|null $jwt = null,
        public bool|null $no_responders = null,
        public bool|null $headers = null,
        public string|null $nkey = null,
    )
    {
    }
}
