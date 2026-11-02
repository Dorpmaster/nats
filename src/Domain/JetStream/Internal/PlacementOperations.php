<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Amp\Cancellation;
use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestException;
use Dorpmaster\Nats\Domain\JetStream\Capability\{Feature,JetStreamCapabilitiesProviderInterface,Support};
use Dorpmaster\Nats\Domain\JetStream\Exception\{JetStreamApiException,JetStreamPlacementException};
use Dorpmaster\Nats\Domain\JetStream\Model\{JsonObject,StreamInfo,ConsumerInfo,PlacementAck};
use Dorpmaster\Nats\Domain\JetStream\Transport\{JetStreamControlPlaneTransport,JetStreamControlPlaneTransportInterface,JetStreamConnectionBoundTransportInterface,JetStreamJsonTransportInterface};
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use Throwable;

/** @internal Admission and one tracked write, independently of placement observation. */
final readonly class PlacementOperations
{
    /** @param \Closure(JsonObject):StreamInfo $streamMapper @param \Closure(JsonObject):ConsumerInfo $consumerMapper */
    public function __construct(private JetStreamControlPlaneTransportInterface $transport, private LoggerInterface $logger, private \Closure $streamMapper, private \Closure $consumerMapper)
    {
    }
    public static function resource(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid ' . $field . ': expected a string');
        }
        ResourceName::validate($value, $field);
        return $value;
    }
    public static function peer(mixed $peer, string $field = 'peer'): void
    {
        if (!is_string($peer) || preg_match('/\A[^\s\p{Cc}*>]+\z/u', $peer) !== 1) {
            throw new InvalidArgumentException('Invalid ' . $field . ': expected a nonempty concrete identifier');
        }
    }
    /** @param array<string,string> $targets */
    public function execute(string $operation, array $targets, mixed $timeoutMs, Cancellation|null $cancellation): StreamInfo|PlacementAck
    {
        $deadline          = new PlacementDeadline($timeoutMs, $cancellation);
        $entered           = false;
        $verifiedRejection = false;
        $type              = null;
        try {
            $deadline->remaining();
            if (!$this->transport instanceof JetStreamConnectionBoundTransportInterface || !$this->transport instanceof JetStreamCapabilitiesProviderInterface) {
                throw new JetStreamApiException(0, 'Placement operations require JetStreamConnectionBoundTransportInterface and current capabilities');
            }
            $capabilities = $this->transport->getCapabilities();
            if ($capabilities->check(Feature::PLACEMENT_MANAGEMENT)->support === Support::UNSUPPORTED) {
                throw new JetStreamApiException(0, 'Placement operations require JetStream API level 5');
            }
            $connection = $capabilities->server;
            if (!$connection->isCurrentConnection() || $connection->connectionId === null) {
                throw new JetStreamApiException(0, 'Placement target connection is not established');
            }
            if ($capabilities->apiPrefix !== '$JS.API') {
                throw new JetStreamApiException(0, 'Placement command route requires the default API namespace; remote aliases cannot be certified');
            }
            [$suffix,$type] = match ($operation) {
                'stream_cancel_move' => ['STREAM.CANCEL_MOVE.' . $targets['stream'], 'stream_cancel_move_response'],
                'stream_peer_evacuate' => ['STREAM.PEER.EVACUATE.' . $targets['stream'], 'stream_evacuate_peer_response'],
                'consumer_peer_evacuate' => ['CONSUMER.PEER.EVACUATE.' . $targets['stream'] . '.' . $targets['consumer'], 'consumer_evacuate_peer_response'],
                'server_evacuate' => ['SERVER.EVACUATE', 'meta_server_evacuate_response'],
            };
            if ($operation === 'server_evacuate') {
                // Never infer a remote/system meta leader's API from the connection endpoint.
                if ($capabilities->apiPrefix !== '$JS.API') {
                    throw new JetStreamApiException(0, 'System evacuation requires the explicit default system API namespace');
                }
                if ($connection->serverId === null) {
                    throw new JetStreamApiException(0, 'System connection server identity is not established');
                }
                self::peer($connection->serverId, 'serverId');
                $diagnostic  = new JetStreamControlPlaneTransport($this->transport->getClient(), $this->transport->getSubscriptionIdHelper(), $this->logger, '$SYS.REQ');
                $local       = $diagnostic->requestJsonOnConnection('SERVER.' . $connection->serverId . '.JSZ', new JsonObject('{}'), $connection->connectionId, $deadline->remaining(), $deadline->cancellation);
                $localServer = (new MetadataFields($local, 'system'))->object('server');
                $localData   = (new MetadataFields($local, 'system'))->object('data');
                $localMeta   = $localData === null ? null : (new MetadataFields($localData, 'system.data'))->object('meta_cluster');
                $leader      = $localMeta === null ? null : (new MetadataFields($localMeta, 'system.meta'))->text('leader');
                $group       = $localMeta === null ? null : (new MetadataFields($localMeta, 'system.meta'))->text('name');
                $domain      = $localServer === null ? null : (new MetadataFields($localServer, 'system.server'))->text('domain');
                if ($localServer === null || $localData === null || $localServer->get('id') !== $connection->serverId || $localData->get('server_id') !== $connection->serverId || $leader === null || $leader === '' || $group === null || $group === '') {
                    throw new JetStreamApiException(0, 'System local cluster identity is not established');
                }
                $filters = ['leader_only' => true, 'server_name' => $leader, 'exact_match' => true];
                if ($domain !== null) {
                    $filters['domain'] = $domain;
                }
                $raw    = $diagnostic->requestJsonOnConnection('SERVER.PING.JSZ', JsonObject::fromArray($filters), $connection->connectionId, $deadline->remaining(), $deadline->cancellation);
                $server = (new MetadataFields($raw, 'system'))->object('server');
                $data   = (new MetadataFields($raw, 'system'))->object('data');
                $meta   = $data === null ? null : (new MetadataFields($data, 'system.data'))->object('meta_cluster');
                if ($server === null || $data === null || $meta === null || !is_string($server->get('id')) || $server->get('id') === '' || $server->get('id') !== $data->get('server_id') || (new MetadataFields($server, 'server'))->text('name') !== $leader || (new MetadataFields($server, 'server'))->text('domain') !== $domain || (new MetadataFields($meta, 'meta_cluster'))->text('leader') !== $leader || (new MetadataFields($meta, 'meta_cluster'))->text('name') !== $group) {
                    throw new JetStreamApiException(0, 'System meta leader identity is not established');
                }
                $this->level((new MetadataFields($data, 'system.data'))->object('api'), 'system.api.level');
            } else {
                $account = $this->read('INFO', $deadline, $connection);
                if ((new MetadataFields($account, 'account'))->text('type') !== 'io.nats.jetstream.api.v1.account_info_response') {
                    throw new JetStreamApiException(0, 'Placement target account type is not established');
                }
                $this->level($account->getObject('api'), 'api.level');
                $this->verifyRoute($connection, $deadline, $targets, $suffix);
                $this->info($targets['stream'], $targets['consumer'] ?? null, $deadline, true, $connection);
            }
            $deadline->remaining();
            if (!$connection->isCurrentConnection()) {
                throw new JetStreamApiException(0, 'Original placement connection is no longer current');
            }
            if ($operation === 'server_evacuate') {
                $this->verifyRoute($connection, $deadline, $targets, $suffix);
            }
            $payload = isset($targets['peer']) ? JsonObject::fromArray(['peer' => $targets['peer']]) : (isset($targets['peer_id']) ? JsonObject::fromArray(['peer_id' => $targets['peer_id']]) : new JsonObject('{}'));
            $this->logger->debug('js.placement.command', ['operation' => $operation, 'targets' => $targets]);
            $remaining = $deadline->remaining();
            $entered   = true;
            $raw       = $this->transport->requestJsonOnConnection($suffix, $payload, $connection->connectionId, $remaining, $deadline->cancellation);
            $error     = ApiError::fromResponse(json_decode($raw->toJson(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), 0, 'Placement error');
            if ($error !== null) {
                $verifiedRejection = $this->isVerifiedRejection($raw, $type, $error);
                throw $error;
            }
            $fields = new MetadataFields($raw, 'placement');
            if ($fields->text('type') !== 'io.nats.jetstream.api.v1.' . $type) {
                throw new JetStreamApiException(0, 'Invalid placement response type');
            }
            if ($operation === 'stream_cancel_move') {
                $info = ($this->streamMapper)($raw);
                if ($info->getName() !== $targets['stream']) {
                    throw new JetStreamApiException(0, 'Cancel move stream identity mismatch');
                }
                return $info;
            }
            if ($fields->boolean('success') !== true) {
                throw new JetStreamApiException(0, 'Placement response does not confirm success=true');
            }
            return new PlacementAck($operation, $targets, $raw);
        } catch (Throwable $error) {
            $unknown = $entered;
            if ($error instanceof ConnectionBoundRequestException) {
                $unknown = $entered && $error->wasAttempted();
            } elseif ($verifiedRejection || ($error instanceof PlacementResponseException && $type !== null && $this->isVerifiedRejection($error->response, $type, $error))) {
                $unknown = false;
            }
            $this->logger->warning('js.placement.failed', ['operation' => $operation, 'outcome_unknown' => $unknown, 'error_type' => $error::class]);
            throw new JetStreamPlacementException($operation, $targets, $unknown, $error);
        }
    }
    private function isVerifiedRejection(JsonObject $raw, string $type, JetStreamApiException $error): bool
    {
        return $raw->get('type') === 'io.nats.jetstream.api.v1.' . $type
            && $error->getApiCode() >= 400 && $error->getApiCode() < 500 && $error->getApiCode() !== 408
            && ($error->getErrorCode() ?? 0) > 0;
    }
    /** @param array<string,string> $targets */
    private function verifyRoute(ServerInformation $connection, PlacementDeadline $deadline, array $targets, string $command): void
    {
        $diagnostic = new JetStreamControlPlaneTransport($this->transport->getClient(), $this->transport->getSubscriptionIdHelper(), $this->logger, '$SYS.REQ');
        $subjects   = ['$JS.API.' . $command];
        if ($command !== 'SERVER.EVACUATE') {
            $subjects[] = '$JS.API.INFO';
            $subjects[] = isset($targets['consumer'])
                ? '$JS.API.CONSUMER.INFO.' . $targets['stream'] . '.' . $targets['consumer']
                : '$JS.API.STREAM.INFO.' . $targets['stream'];
        }
        $topology = new PlacementRoute(static function (string $subject, array $payload) use ($connection, $deadline, $diagnostic): JsonObject {
            if (!$connection->isCurrentConnection()) {
                throw new JetStreamApiException(0, 'Original placement connection is no longer current');
            }
            return $diagnostic->requestJsonOnConnection($subject, JsonObject::fromArray($payload), $connection->connectionId, $deadline->remaining(), $deadline->cancellation);
        });
        $topology->verify($connection->serverId, $subjects, $command === 'SERVER.EVACUATE');
    }
    public function connection(): ServerInformation
    {
        if (!$this->transport instanceof JetStreamConnectionBoundTransportInterface || !$this->transport instanceof JetStreamCapabilitiesProviderInterface) {
            throw new JetStreamApiException(0, 'Placement reads require JetStreamConnectionBoundTransportInterface and current capabilities');
        }
        $connection = $this->transport->getCapabilities()->server;
        if (!$connection->isCurrentConnection() || $connection->connectionId === null) {
            throw new JetStreamApiException(0, 'The placement read connection is no longer current');
        }
        return $connection;
    }
    private function level(JsonObject|null $api, string $path): void
    {
        $value = $api === null ? null : (new MetadataFields($api, $path))->decimal('level', (string)PHP_INT_MAX);
        if ($value === null || Decimal::compare($value, '5') < 0) {
            throw new JetStreamApiException(0, 'Placement target ' . $path . ' >=5 is not established');
        }
    }
    public function read(string $suffix, PlacementDeadline $deadline, ServerInformation|null $connection = null): JsonObject
    {
        $connection ??= $this->connection();
        if (!$connection->isCurrentConnection() || $connection->connectionId === null) {
            throw new JetStreamApiException(0, 'The placement read connection is no longer current');
        }
        $raw   = $this->transport->requestJsonOnConnection($suffix, new JsonObject('{}'), $connection->connectionId, $deadline->remaining(), $deadline->cancellation);
        $error = ApiError::fromResponse(json_decode($raw->toJson(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), 0, 'Placement INFO error');
        if ($error !== null) {
            throw $error;
        }
        return $raw;
    }
    public function info(string $stream, string|null $consumer, PlacementDeadline $deadline, bool $requireLevel = false, ServerInformation|null $connection = null): StreamInfo|ConsumerInfo
    {
        try {
            $raw    = $this->read($consumer === null ? 'STREAM.INFO.' . $stream : 'CONSUMER.INFO.' . $stream . '.' . $consumer, $deadline, $connection);
            $fields = new MetadataFields($raw, 'placement.info');
            $config = $fields->object('config');
            if ($fields->text('type') !== 'io.nats.jetstream.api.v1.' . ($consumer === null ? 'stream_info_response' : 'consumer_info_response') || $config === null) {
                throw new JetStreamApiException(0, 'Invalid placement INFO type/config');
            }
            $cf = new MetadataFields($config, 'config');
            if ($consumer === null ? $cf->text('name') !== $stream : ($fields->text('stream_name') !== $stream || $fields->text('name') !== $consumer || ($config->has('durable_name') && $cf->text('durable_name') !== $consumer) || ($config->has('name') && $cf->text('name') !== $consumer))) {
                throw new JetStreamApiException(0, 'Placement target INFO identity mismatch');
            }
            if ($requireLevel) {
                $metadata = $cf->object('metadata');
                $level    = $metadata === null ? null : (new MetadataFields($metadata, 'config.metadata'))->text('_nats.level');
                if ($level === null || preg_match('/\A[0-9]+\z/', $level) !== 1 || Decimal::compare(Decimal::parse($level, (string)PHP_INT_MAX), '5') < 0) {
                    throw new JetStreamApiException(0, 'Placement target resource API level 5 is not established');
                }
            }
            return $consumer === null ? ($this->streamMapper)($raw) : ($this->consumerMapper)($raw);
        } catch (InvalidArgumentException | \JsonException $error) {
            throw new JetStreamApiException(0, 'Invalid placement INFO metadata: ' . $error->getMessage(), $error);
        }
    }
}
