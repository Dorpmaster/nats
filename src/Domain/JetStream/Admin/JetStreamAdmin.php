<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Admin;

use Amp\CancelledException;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilities;
use Dorpmaster\Nats\Domain\JetStream\Capability\Feature;
use Dorpmaster\Nats\Domain\JetStream\Capability\Support;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilitiesProviderInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamConfigurationUnconfirmedException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Internal\ResourceName;
use Dorpmaster\Nats\Domain\JetStream\Internal\StreamSourceAdmission;
use Dorpmaster\Nats\Domain\JetStream\Internal\ApiError;
use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;
use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;
use Dorpmaster\Nats\Domain\JetStream\Internal\LegacyMetadataResponse;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerInfo;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerResetResult;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamJsonTransportInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamSingleAttemptTransportInterface;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class JetStreamAdmin implements JetStreamBackupAdminInterface, JetStreamPlacementAdminInterface, JetStreamUpdatingAdminInterface, JetStreamResettingAdminInterface, JetStreamCapabilitiesProviderInterface
{
    public function __construct(
        private JetStreamControlPlaneTransportInterface $transport,
        private LoggerInterface|null $logger = null,
    ) {
    }

    public function getCapabilities(): JetStreamCapabilities
    {
        return $this->transport instanceof JetStreamCapabilitiesProviderInterface
            ? $this->transport->getCapabilities()
            : new JetStreamCapabilities(new \Dorpmaster\Nats\Domain\Client\ServerInformation());
    }

    private function archives(): \Dorpmaster\Nats\Domain\JetStream\Backup\ArchiveOperations
    {
        return new \Dorpmaster\Nats\Domain\JetStream\Backup\ArchiveOperations($this->transport, $this->getLogger(), fn (JsonObject $raw): StreamInfo => $this->mapStreamInfo($raw));
    }
    public function backupStream(mixed $stream, \Amp\ByteStream\WritableStream $output, \Dorpmaster\Nats\Domain\JetStream\Backup\BackupOptions|null $options = null, mixed $timeoutMs = null, \Amp\Cancellation|null $cancellation = null): \Dorpmaster\Nats\Domain\JetStream\Backup\StreamBackupMetadata
    {
        if (!is_string($stream)) {
            throw new InvalidArgumentException('Invalid stream: expected string');
        }
        return $this->archives()->backup($stream, $output, $options ?? new \Dorpmaster\Nats\Domain\JetStream\Backup\BackupOptions(), $timeoutMs, $cancellation);
    }
    public function restoreStream(mixed $destination, \Amp\ByteStream\ReadableStream $input, \Dorpmaster\Nats\Domain\JetStream\Backup\StreamBackupMetadata $backup, mixed $storage, \Dorpmaster\Nats\Domain\JetStream\Backup\RestoreOptions|null $options = null, mixed $timeoutMs = null, \Amp\Cancellation|null $cancellation = null): StreamInfo
    {
        if (!is_string($destination)) {
            throw new InvalidArgumentException('Invalid destination: expected string');
        }
        if (!is_string($storage)) {
            throw new InvalidArgumentException('Invalid storage: expected file or memory');
        }
        return $this->archives()->restore($destination, $input, $backup, $storage, $options ?? new \Dorpmaster\Nats\Domain\JetStream\Backup\RestoreOptions(), $timeoutMs, $cancellation);
    }

    private function placement(): \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementOperations
    {
        return new \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementOperations($this->transport, $this->getLogger(), fn (JsonObject $raw): StreamInfo => $this->mapStreamInfo($raw), fn (JsonObject $raw): ConsumerInfo => $this->mapConsumerInfo($raw));
    }
    public function cancelStreamMove(mixed $stream, mixed $timeoutMs = null, \Amp\Cancellation|null $cancellation = null): StreamInfo
    {
        $stream = \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementOperations::resource($stream, 'stream');
        return $this->placement()->execute('stream_cancel_move', ['stream' => $stream], $timeoutMs, $cancellation);
    }
    public function evacuateStreamPeer(mixed $stream, mixed $peer, mixed $timeoutMs = null, \Amp\Cancellation|null $cancellation = null): \Dorpmaster\Nats\Domain\JetStream\Model\PlacementAck
    {
        $stream = \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementOperations::resource($stream, 'stream');
        \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementOperations::peer($peer);
        return $this->placement()->execute('stream_peer_evacuate', ['stream' => $stream,'peer' => $peer], $timeoutMs, $cancellation);
    }
    public function evacuateConsumerPeer(mixed $stream, mixed $consumer, mixed $peer, mixed $timeoutMs = null, \Amp\Cancellation|null $cancellation = null): \Dorpmaster\Nats\Domain\JetStream\Model\PlacementAck
    {
        $stream   = \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementOperations::resource($stream, 'stream');
        $consumer = \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementOperations::resource($consumer, 'consumer');
        \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementOperations::peer($peer);
        return $this->placement()->execute('consumer_peer_evacuate', ['stream' => $stream,'consumer' => $consumer,'peer' => $peer], $timeoutMs, $cancellation);
    }
    public function evacuateServer(mixed $peer = null, mixed $peerId = null, mixed $timeoutMs = null, \Amp\Cancellation|null $cancellation = null): \Dorpmaster\Nats\Domain\JetStream\Model\PlacementAck
    {
        if (($peer === null) === ($peerId === null)) {
            throw new InvalidArgumentException('Specify exactly one of peer or peerId');
        }
        \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementOperations::peer($peer ?? $peerId, $peer === null ? 'peerId' : 'peer');
        return $this->placement()->execute('server_evacuate', $peer === null ? ['peer_id' => $peerId] : ['peer' => $peer], $timeoutMs, $cancellation);
    }
    public function waitForStreamEvacuation(string $stream, string $peerName, int $expectedReplicas, int|null $timeoutMs = null, \Amp\Cancellation|null $cancellation = null): StreamInfo
    {
        return (new \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementObserver($this->placement()))->evacuate($stream, null, $peerName, $expectedReplicas, $timeoutMs, $cancellation);
    }
    public function waitForConsumerEvacuation(string $stream, string $consumer, string $peerName, int $expectedReplicas, int|null $timeoutMs = null, \Amp\Cancellation|null $cancellation = null): ConsumerInfo
    {
        return (new \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementObserver($this->placement()))->evacuate($stream, $consumer, $peerName, $expectedReplicas, $timeoutMs, $cancellation);
    }
    public function waitForStreamRollback(StreamInfo $transition, StreamInfo $original, int|null $timeoutMs = null, \Amp\Cancellation|null $cancellation = null): StreamInfo
    {
        return (new \Dorpmaster\Nats\Domain\JetStream\Internal\PlacementObserver($this->placement()))->rollback($transition, $original, $timeoutMs, $cancellation);
    }

    public function createStream(StreamConfig $config): StreamInfo
    {
        ResourceName::validate($config->getName(), 'stream');
        $payload = $config->toRequestPayload();
        $this->sourceAdmission()->verify(JsonObject::fromArray($payload));

        $this->getLogger()->info('js.admin.stream.create', [
            'stream' => $config->getName(),
            'subjects' => count($config->getSubjects()),
        ]);

        try {
            $response = $this->streamRequest(
                sprintf('STREAM.CREATE.%s', $config->getName()),
                $payload,
            );
        } catch (\Throwable $exception) {
            $this->getLogger()->warning('js.admin.stream.create.failed', [
                'stream' => $config->getName(),
                'error' => $exception::class,
            ]);

            throw $exception;
        }

        $info = $this->mapStreamInfo($response);
        $this->confirmConfiguration($info, array_filter([
            'allow_atomic' => $config->getAllowAtomicPublish(),
            'allow_batched' => $config->getAllowBatchPublish(),
            'max_consumers' => $config->getMaxConsumers(),
            'allow_msg_schedules' => $config->getAllowMessageSchedules(),
            'allow_msg_ttl' => $config->getAllowMessageTtl(),
            'mirror' => $payload['mirror'] ?? null,
            'sources' => $payload['sources'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));
        $this->getLogger()->debug('js.admin.stream.create.success', [
            'operation' => 'stream_create',
            'subjects' => count($info->getSubjects()),
        ]);

        return $info;
    }

    public function getStreamInfo(string $stream): StreamInfo
    {
        ResourceName::validate($stream, 'stream');

        $this->getLogger()->debug('js.admin.stream.info', [
            'stream' => $stream,
        ]);

        try {
            $response = $this->streamRequest(
                sprintf('STREAM.INFO.%s', $stream),
                [],
            );
        } catch (\Throwable $exception) {
            $this->getLogger()->warning('js.admin.stream.info.failed', [
                'stream' => $stream,
                'error' => $exception::class,
            ]);

            throw $exception;
        }

        return $this->mapStreamInfo($response);
    }

    public function updateStream(string $stream, StreamConfigPatch $patch, int|null $timeoutMs = null): StreamInfo
    {
        ResourceName::validate($stream, 'stream');
        $fields = $patch->toRequestPayload();
        if ($fields === []) {
            throw new InvalidArgumentException('Invalid patch: at least one field must be specified');
        }
        $timeout = $timeoutMs ?? 2000;
        if ($timeout <= 0) {
            throw new InvalidArgumentException('Invalid timeoutMs: expected a positive duration');
        }
        if (!$this->transport instanceof JetStreamSingleAttemptTransportInterface) {
            throw new JetStreamApiException(0, 'Stream UPDATE requires JetStreamJsonTransportInterface and JetStreamSingleAttemptTransportInterface to preserve configuration without replay');
        }
        $deadline  = hrtime(true) / 1_000_000 + $timeout;
        $remaining = static function () use ($deadline): int {
            $milliseconds = (int) floor($deadline - hrtime(true) / 1_000_000);
            if ($milliseconds <= 0) {
                throw new JetStreamTimeoutException('JetStream stream update timed out');
            }
            return $milliseconds;
        };
        $this->getLogger()->debug('js.admin.stream.update', ['operation' => 'stream_update', 'fields' => array_keys($fields)]);
        try {
            $current = $this->mapStreamInfo($this->transport->requestJson(
                'STREAM.INFO.' . $stream,
                JsonObject::fromArray([]),
                $remaining(),
            ));
            if ($current->getName() !== $stream) {
                throw new JetStreamApiException(0, 'JetStream INFO returned a different stream name');
            }
            $configuration = $current->getConfiguration();
            assert($configuration !== null);
            StreamSourceAdmission::validateSnapshot($configuration, $current->getResponse());
            $effective = $configuration->withFields($fields);
            $this->sourceAdmission($remaining)->verify($effective, $configuration);
            $response = $this->transport->requestJsonOnce(
                'STREAM.UPDATE.' . $stream,
                $effective,
                $remaining(),
            );
            $remaining();
            $info = $this->mapStreamInfo($response);
            if ($info->getName() !== $stream) {
                throw new JetStreamApiException(0, 'JetStream UPDATE returned a different stream name');
            }
            $this->confirmConfiguration($info, $fields);
            return $info;
        } catch (CancelledException $error) {
            throw new JetStreamTimeoutException('JetStream stream update timed out', $error);
        } catch (\Throwable $error) {
            $this->getLogger()->warning('js.admin.stream.update.failed', [
                'operation' => 'stream_update',
                'error_type' => $error::class,
                'code' => $error instanceof JetStreamApiException ? $error->getApiCode() : null,
                'err_code' => $error instanceof JetStreamApiException ? $error->getErrorCode() : null,
            ]);
            throw $error;
        }
    }

    public function deleteStream(string $stream): void
    {
        ResourceName::validate($stream, 'stream');

        $this->getLogger()->info('js.admin.stream.delete', [
            'stream' => $stream,
        ]);
        try {
            $this->transport->request(
                sprintf('STREAM.DELETE.%s', $stream),
                [],
            );
        } catch (\Throwable $exception) {
            $this->getLogger()->warning('js.admin.stream.delete.failed', [
                'stream' => $stream,
                'error' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function createOrUpdateConsumer(string $stream, ConsumerConfig $config): ConsumerInfo
    {
        ResourceName::validate($stream, 'stream');
        ResourceName::validate($config->getDurableName(), 'consumer');
        if ($config->getAckPolicy() === 'flow_control') {
            $this->sourceAdmission()->requireSupport();
        }

        $this->getLogger()->info('js.admin.consumer.create', [
            'stream' => $stream,
            'consumer' => $config->getDurableName(),
        ]);

        try {
            $response = $this->streamRequest(
                sprintf('CONSUMER.CREATE.%s.%s', $stream, $config->getDurableName()),
                [
                    'stream_name' => $stream,
                    'config' => $config->toRequestPayload(),
                ],
            );
        } catch (\Throwable $exception) {
            $this->getLogger()->warning('js.admin.consumer.create.failed', [
                'stream' => $stream,
                'consumer' => $config->getDurableName(),
                'error' => $exception::class,
            ]);

            throw $exception;
        }

        $info = $this->mapConsumerInfo($response);
        if ($config->getAckPolicy() === 'flow_control') {
            try {
                $raw    = $info->getConfiguration() ?? new JsonObject('{}');
                $actual = new MetadataFields($raw, 'consumer.config');
                if ($info->getStreamName() !== $stream || $info->getName() !== $config->getDurableName() || $info->getDurableName() !== $config->getDurableName() || ($raw->has('name') && $actual->text('name') !== $config->getDurableName()) || $actual->text('ack_policy') !== 'flow_control' || $actual->text('deliver_subject') !== $config->toRequestPayload()['deliver_subject'] || $actual->boolean('flow_control') !== true || $actual->decimal('idle_heartbeat') !== '1000000000') {
                    throw new JetStreamApiException(0, 'Requested flow_control push consumer configuration is unconfirmed');
                }
            } catch (InvalidArgumentException | JsonException $error) {
                throw new JetStreamApiException(0, 'Invalid flow_control consumer metadata: ' . $error->getMessage(), $error);
            }
        }
        $this->getLogger()->debug('js.admin.consumer.create.success', [
            'stream' => $stream,
            'operation' => 'consumer_create',
        ]);

        return $info;
    }

    public function getConsumerInfo(string $stream, string $consumer): ConsumerInfo
    {
        ResourceName::validate($stream, 'stream');
        ResourceName::validate($consumer, 'consumer');

        $this->getLogger()->debug('js.admin.consumer.info', [
            'stream' => $stream,
            'consumer' => $consumer,
        ]);

        try {
            $response = $this->streamRequest(
                sprintf('CONSUMER.INFO.%s.%s', $stream, $consumer),
                [],
            );
        } catch (\Throwable $exception) {
            $this->getLogger()->warning('js.admin.consumer.info.failed', [
                'stream' => $stream,
                'consumer' => $consumer,
                'error' => $exception::class,
            ]);

            throw $exception;
        }

        return $this->mapConsumerInfo($response);
    }

    /** @param int|string|null $streamSequence Only these original types are accepted, in either caller mode. */
    public function resetConsumer(
        string $stream,
        string $consumer,
        mixed $streamSequence = null,
        int|null $timeoutMs = null,
    ): ConsumerResetResult {
        ResourceName::validate($stream, 'stream');
        ResourceName::validate($consumer, 'consumer');
        // A scalar union would coerce float/bool before this method when called from a weak PHP file.
        if ($streamSequence !== null && !is_int($streamSequence) && !is_string($streamSequence)) {
            throw new InvalidArgumentException('Invalid streamSequence: expected int, string or null');
        }
        try {
            $sequence = $streamSequence === null ? '0' : Decimal::parse((string) $streamSequence, Decimal::UINT64_MAX);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException('Invalid streamSequence: expected an unsigned decimal UInt64', 0, $error);
        }
        $timeout = $timeoutMs ?? 2000;
        if ($timeout <= 0) {
            throw new InvalidArgumentException('Invalid timeoutMs: expected a positive duration');
        }
        if ($this->getCapabilities()->check(Feature::CONSUMER_RESET)->support === Support::UNSUPPORTED) {
            throw new JetStreamApiException(0, 'Consumer RESET is unsupported by the current server API level');
        }
        if (!$this->transport instanceof JetStreamSingleAttemptTransportInterface) {
            throw new JetStreamApiException(0, 'Consumer RESET requires JetStreamSingleAttemptTransportInterface to prevent replay');
        }
        // The validated decimal is written as a number token, never as a quoted PHP string or float.
        $payload = new JsonObject($sequence === '0' ? '{}' : '{"seq":' . $sequence . '}');
        $this->getLogger()->debug('js.admin.consumer.reset', ['operation' => 'consumer_reset', 'timeout_ms' => $timeout]);
        try {
            $response = $this->transport->requestJsonOnce('CONSUMER.RESET.' . $stream . '.' . $consumer, $payload, $timeout);
            $apiError = ApiError::fromResponse(json_decode($response->toJson(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), 0, 'JetStream consumer reset error');
            if ($apiError !== null) {
                throw $apiError;
            }
            try {
                $fields = new MetadataFields($response, 'consumer_reset');
                if ($fields->text('type') !== 'io.nats.jetstream.api.v1.consumer_reset_response') {
                    throw new JetStreamApiException(0, 'Invalid consumer reset response type');
                }
                $resetSequence = $fields->decimal('reset_seq');
                if ($resetSequence === null || $resetSequence === '0') {
                    throw new JetStreamApiException(0, 'Invalid consumer reset response reset_seq');
                }
                $info          = $this->mapConsumerInfo($response);
                $configuration = $info->getConfiguration();
                if ($configuration !== null && $configuration->has('name') && (new MetadataFields($configuration, 'config'))->text('name') !== $consumer) {
                    throw new JetStreamApiException(0, 'Consumer reset response config.name does not match consumer');
                }
                if ($info->getStreamName() !== $stream || $info->getName() !== $consumer || $info->getDurableName() !== $consumer) {
                    throw new JetStreamApiException(0, 'Consumer reset response stream/consumer identity mismatch');
                }
            } catch (InvalidArgumentException | JsonException $error) {
                throw new JetStreamApiException(0, 'Invalid consumer reset metadata: ' . $error->getMessage(), $error);
            }
            $this->getLogger()->debug('js.admin.consumer.reset.success', ['operation' => 'consumer_reset', 'ok' => true]);
            return new ConsumerResetResult($resetSequence, $info);
        } catch (\Throwable $error) {
            if ($error instanceof CancelledException) {
                $error = new JetStreamTimeoutException('JetStream consumer reset timed out', $error);
            }
            $this->getLogger()->warning('js.admin.consumer.reset.failed', [
                'operation' => 'consumer_reset',
                'error_type' => $error::class,
                'code' => $error instanceof JetStreamApiException ? $error->getApiCode() : null,
                'err_code' => $error instanceof JetStreamApiException ? $error->getErrorCode() : null,
            ]);
            throw $error;
        }
    }

    public function deleteConsumer(string $stream, string $consumer): void
    {
        ResourceName::validate($stream, 'stream');
        ResourceName::validate($consumer, 'consumer');

        $this->getLogger()->info('js.admin.consumer.delete', [
            'stream' => $stream,
            'consumer' => $consumer,
        ]);

        try {
            $this->transport->request(
                sprintf('CONSUMER.DELETE.%s.%s', $stream, $consumer),
                [],
            );
        } catch (\Throwable $exception) {
            $this->getLogger()->warning('js.admin.consumer.delete.failed', [
                'stream' => $stream,
                'consumer' => $consumer,
                'error' => $exception::class,
            ]);

            throw $exception;
        }
    }

    /** @param array<string, mixed>|JsonObject $response */
    private function mapStreamInfo(array|JsonObject $response): StreamInfo
    {
        try {
            $raw           = $response instanceof JsonObject ? $response : LegacyMetadataResponse::fromArray($response);
            $configuration = (new MetadataFields($raw, 'stream'))->object('config');
            if ($configuration === null) {
                throw new JetStreamApiException(0, 'JetStream stream response does not contain config object');
            }
            StreamSourceAdmission::validateSnapshot($configuration);
            $name = (new MetadataFields($configuration, 'config'))->text('name');
            if ($name === null || $name === '') {
                throw new JetStreamApiException(0, 'JetStream stream response does not contain valid stream name');
            }
            $subjects = \Dorpmaster\Nats\Domain\JetStream\Internal\StreamSubjects::read($configuration);
            return new StreamInfo($name, $subjects, $configuration, $raw);
        } catch (InvalidArgumentException | JsonException $error) {
            throw new JetStreamApiException(0, 'Invalid JetStream stream metadata: ' . $error->getMessage(), $error);
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>|JsonObject
     */
    private function streamRequest(string $suffix, array $payload, int|null $timeoutMs = null): array|JsonObject
    {
        try {
            return $this->transport instanceof JetStreamJsonTransportInterface
                ? $this->transport->requestJson($suffix, JsonObject::fromArray($payload), $timeoutMs)
                : $this->transport->request($suffix, $payload, $timeoutMs);
        } catch (JsonException $error) {
            throw new JetStreamApiException(0, 'Failed to encode JetStream API request payload as JSON', $error);
        }
    }

    /** @param array<string, mixed> $fields */
    private function confirmConfiguration(StreamInfo $info, array $fields): void
    {
        $configuration = $info->getConfiguration();
        $unconfirmed   = [];
        foreach ($fields as $field => $expected) {
            $actual = $configuration?->get($field);
            if ($field === 'mirror' || $field === 'sources') {
                if (($field === 'sources' && $expected === [] && $actual === null) || $this->containsConfiguration($actual, $expected)) {
                    continue;
                }
                $unconfirmed[] = $field;
                continue;
            }
            // NATS omits false boolean fields and normalizes max_consumers=0 to -1.
            $actual = !$configuration?->has($field) && is_bool($expected) ? false : $actual;
            if ($actual === $expected || ($field === 'max_consumers' && $expected === 0 && $actual === -1)) {
                continue;
            }
            $unconfirmed[] = $field;
        }
        if ($unconfirmed !== []) {
            throw new JetStreamConfigurationUnconfirmedException($info, $unconfirmed);
        }
    }

    private function sourceAdmission(\Closure|null $remaining = null): StreamSourceAdmission
    {
        return new StreamSourceAdmission(
            $this->getCapabilities(),
            fn (string $suffix, array $payload): array|JsonObject => $this->streamRequest($suffix, $payload, $remaining === null ? 2000 : $remaining()),
            fn (string $prefix, string $suffix, array $payload): JsonObject => (new JetStreamControlPlaneTransport(
                $this->transport->getClient(),
                $this->transport->getSubscriptionIdHelper(),
                $this->getLogger(),
                $prefix,
            ))->requestJson($suffix, JsonObject::fromArray($payload), $remaining === null ? 2000 : $remaining()),
        );
    }

    /** Confirm requested fields while tolerating server-added nested metadata. */
    private function containsConfiguration(mixed $actual, mixed $expected, string $field = ''): bool
    {
        if ($field === 'opt_start_time' && is_string($actual) && is_string($expected)) {
            try {
                return \Dorpmaster\Nats\Domain\JetStream\Model\NanoTimestamp::fromRfc3339($actual)->getUnixNanoseconds() === \Dorpmaster\Nats\Domain\JetStream\Model\NanoTimestamp::fromRfc3339($expected)->getUnixNanoseconds();
            } catch (InvalidArgumentException) {
                return false;
            }
        }
        if (!is_array($expected)) {
            return $actual === $expected;
        }
        if (array_is_list($expected)) {
            if (!is_array($actual) || count($actual) !== count($expected)) {
                return false;
            }
        } elseif ($actual instanceof \stdClass) {
            $actual = get_object_vars($actual);
        } else {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || !$this->containsConfiguration($actual[$key], $value, (string) $key)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed>|JsonObject $response */
    private function mapConsumerInfo(array|JsonObject $response): ConsumerInfo
    {
        try {
            $raw           = $response instanceof JsonObject ? $response : LegacyMetadataResponse::fromArray($response);
            $fields        = new MetadataFields($raw, 'consumer');
            $configuration = $fields->object('config');
            $durableName   = (new MetadataFields($configuration ?? new JsonObject('{}'), 'config'))->text('durable_name');
            if ($durableName === null || $durableName === '') {
                $durableName = $fields->text('name');
            }
            if ($durableName === null || $durableName === '') {
                throw new JetStreamApiException(0, 'JetStream consumer response does not contain durable name');
            }
            return new ConsumerInfo(
                $durableName,
                Decimal::toInt($fields->decimal('num_pending')),
                Decimal::toInt($fields->decimal('num_ack_pending', Decimal::INT64_MAX)),
                $configuration,
                $raw,
            );
        } catch (InvalidArgumentException | JsonException $error) {
            throw new JetStreamApiException(0, 'Invalid JetStream consumer metadata: ' . $error->getMessage(), $error);
        }
    }

    private function getLogger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}
