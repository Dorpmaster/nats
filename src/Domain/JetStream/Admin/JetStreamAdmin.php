<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Admin;

use Amp\CancelledException;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilities;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilitiesProviderInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamConfigurationUnconfirmedException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Internal\ResourceName;
use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;
use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;
use Dorpmaster\Nats\Domain\JetStream\Internal\LegacyMetadataResponse;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerInfo;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamJsonTransportInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamSingleAttemptTransportInterface;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class JetStreamAdmin implements JetStreamUpdatingAdminInterface, JetStreamCapabilitiesProviderInterface
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

    public function createStream(StreamConfig $config): StreamInfo
    {
        ResourceName::validate($config->getName(), 'stream');

        $this->getLogger()->info('js.admin.stream.create', [
            'stream' => $config->getName(),
            'subjects' => count($config->getSubjects()),
        ]);

        try {
            $response = $this->streamRequest(
                sprintf('STREAM.CREATE.%s', $config->getName()),
                $config->toRequestPayload(),
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
            $response = $this->transport->requestJsonOnce(
                'STREAM.UPDATE.' . $stream,
                $configuration->withFields($fields),
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
            $name = (new MetadataFields($configuration, 'config'))->text('name');
            if ($name === null || $name === '') {
                throw new JetStreamApiException(0, 'JetStream stream response does not contain valid stream name');
            }
            // This schema requires strings: never let JSON_BIGINT_AS_STRING disguise numeric elements.
            $subjects = $configuration->has('subjects')
                ? json_decode($configuration->getRawValue('subjects'), false, 512, JSON_THROW_ON_ERROR)
                : [];
            if (!is_array($subjects) || !array_is_list($subjects) || array_filter($subjects, static fn (mixed $subject): bool => !is_string($subject)) !== []) {
                throw new JetStreamApiException(0, 'JetStream stream response contains invalid subjects list');
            }
            return new StreamInfo($name, $subjects, $configuration, $raw);
        } catch (InvalidArgumentException | JsonException $error) {
            throw new JetStreamApiException(0, 'Invalid JetStream stream metadata: ' . $error->getMessage(), $error);
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>|JsonObject
     */
    private function streamRequest(string $suffix, array $payload): array|JsonObject
    {
        try {
            return $this->transport instanceof JetStreamJsonTransportInterface
                ? $this->transport->requestJson($suffix, JsonObject::fromArray($payload))
                : $this->transport->request($suffix, $payload);
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
