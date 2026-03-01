<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Admin;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerInfo;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;

final readonly class JetStreamAdmin implements JetStreamAdminInterface
{
    public function __construct(
        private JetStreamControlPlaneTransportInterface $transport,
    ) {
    }

    public function createStream(StreamConfig $config): StreamInfo
    {
        $response = $this->transport->request(
            sprintf('STREAM.CREATE.%s', $config->getName()),
            $config->toRequestPayload(),
        );

        return $this->mapStreamInfo($response);
    }

    public function getStreamInfo(string $stream): StreamInfo
    {
        $response = $this->transport->request(
            sprintf('STREAM.INFO.%s', $stream),
            [],
        );

        return $this->mapStreamInfo($response);
    }

    public function deleteStream(string $stream): void
    {
        $this->transport->request(
            sprintf('STREAM.DELETE.%s', $stream),
            [],
        );
    }

    public function createOrUpdateConsumer(string $stream, ConsumerConfig $config): ConsumerInfo
    {
        $response = $this->transport->request(
            sprintf('CONSUMER.CREATE.%s.%s', $stream, $config->getDurableName()),
            [
                'stream_name' => $stream,
                'config' => $config->toRequestPayload(),
            ],
        );

        return $this->mapConsumerInfo($response);
    }

    public function getConsumerInfo(string $stream, string $consumer): ConsumerInfo
    {
        $response = $this->transport->request(
            sprintf('CONSUMER.INFO.%s.%s', $stream, $consumer),
            [],
        );

        return $this->mapConsumerInfo($response);
    }

    public function deleteConsumer(string $stream, string $consumer): void
    {
        $this->transport->request(
            sprintf('CONSUMER.DELETE.%s.%s', $stream, $consumer),
            [],
        );
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

        return new ConsumerInfo($durableName);
    }
}
