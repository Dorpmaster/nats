<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Admin;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerInfo;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class JetStreamAdmin implements JetStreamAdminInterface
{
    public function __construct(
        private JetStreamControlPlaneTransportInterface $transport,
        private LoggerInterface|null $logger = null,
    ) {
    }

    public function createStream(StreamConfig $config): StreamInfo
    {
        $this->getLogger()->info('js.admin.stream.create', [
            'stream' => $config->getName(),
            'subjects' => count($config->getSubjects()),
        ]);

        try {
            $response = $this->transport->request(
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
        $this->getLogger()->debug('js.admin.stream.create.success', [
            'stream' => $info->getName(),
            'subjects' => count($info->getSubjects()),
        ]);

        return $info;
    }

    public function getStreamInfo(string $stream): StreamInfo
    {
        $this->getLogger()->debug('js.admin.stream.info', [
            'stream' => $stream,
        ]);

        try {
            $response = $this->transport->request(
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

    public function deleteStream(string $stream): void
    {
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
        $this->getLogger()->info('js.admin.consumer.create', [
            'stream' => $stream,
            'consumer' => $config->getDurableName(),
        ]);

        try {
            $response = $this->transport->request(
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
            'consumer' => $info->getDurableName(),
        ]);

        return $info;
    }

    public function getConsumerInfo(string $stream, string $consumer): ConsumerInfo
    {
        $this->getLogger()->debug('js.admin.consumer.info', [
            'stream' => $stream,
            'consumer' => $consumer,
        ]);

        try {
            $response = $this->transport->request(
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

    /** @param array<string, mixed> $response */
    private function mapStreamInfo(array $response): StreamInfo
    {
        if (!isset($response['config']) || !is_array($response['config'])) {
            throw new JetStreamApiException(0, 'JetStream stream response does not contain config object');
        }

        $config = $response['config'];
        $name   = $config['name'] ?? null;

        if (!is_string($name) || $name === '') {
            throw new JetStreamApiException(0, 'JetStream stream response does not contain valid stream name');
        }

        $subjects = $config['subjects'] ?? [];
        if (!is_array($subjects)) {
            throw new JetStreamApiException(0, 'JetStream stream response contains invalid subjects list');
        }

        $normalizedSubjects = [];
        foreach ($subjects as $subject) {
            if (is_string($subject)) {
                $normalizedSubjects[] = $subject;
            }
        }

        return new StreamInfo($name, $normalizedSubjects);
    }

    /** @param array<string, mixed> $response */
    private function mapConsumerInfo(array $response): ConsumerInfo
    {
        $durableName = null;

        if (isset($response['config']) && is_array($response['config'])) {
            $candidate = $response['config']['durable_name'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $durableName = $candidate;
            }
        }

        if ($durableName === null && isset($response['name']) && is_string($response['name']) && $response['name'] !== '') {
            $durableName = $response['name'];
        }

        if ($durableName === null) {
            throw new JetStreamApiException(0, 'JetStream consumer response does not contain durable name');
        }

        $numPending    = isset($response['num_pending']) && is_numeric($response['num_pending'])
            ? (int) $response['num_pending']
            : null;
        $numAckPending = isset($response['num_ack_pending']) && is_numeric($response['num_ack_pending'])
            ? (int) $response['num_ack_pending']
            : null;

        return new ConsumerInfo($durableName, $numPending, $numAckPending);
    }

    private function getLogger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}
