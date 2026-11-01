<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Capability\Feature;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Internal\ApiError;
use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneRequestMessage;
use Dorpmaster\Nats\Protocol\Contracts\StatusMessageInterface;

/** @internal Read-only confirmation of the stream and every subject in the default API scope. */
final readonly class AtomicBatchTarget
{
    public function __construct(private ConnectionBoundRequestClientInterface $client)
    {
    }

    /** @param list<string> $subjects */
    public function verify(string $stream, array $subjects, Feature $feature, int $connectionId, float $deadline, AtomicBatchOptions $options, string|null $serverId): void
    {
        $info   = $this->read('$JS.API.STREAM.INFO.' . $stream, '{}', $connectionId, $deadline, $options);
        $config = $info->getObject('config');
        if ($info->get('type') !== 'io.nats.jetstream.api.v1.stream_info_response' || $config === null || (new MetadataFields($config, 'config'))->text('name') !== $stream) {
            throw new JetStreamApiException(0, 'Atomic target stream identity is not established');
        }
        $metadata = $config->getObject('metadata');
        $level    = $metadata === null ? null : (new MetadataFields($metadata, 'config.metadata'))->text('_nats.level');
        if ($level === null || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $level) !== 1 || strlen($level) > 9 || (int) $level < $feature->minimumApiLevel()) {
            throw new JetStreamApiException(0, 'Atomic target stream mode support is not established: ' . $feature->value);
        }
        $permission = $feature === Feature::FAST_BATCH_PUBLISH ? 'allow_batched' : 'allow_atomic';
        if ((new MetadataFields($config, 'config'))->boolean($permission) !== true) {
            throw new JetStreamApiException(400, $permission === 'allow_atomic' ? 'Atomic publish is disabled for target stream' : 'Fast batch publish is disabled for target stream', errorCode: 10174);
        }
        foreach (array_unique($subjects) as $subject) {
            $names = $this->read('$JS.API.STREAM.NAMES', json_encode(['subject' => $subject], JSON_THROW_ON_ERROR), $connectionId, $deadline, $options);
            if ($names->get('type') !== 'io.nats.jetstream.api.v1.stream_names_response' || $names->get('streams') !== [$stream] || (new MetadataFields($names, 'stream_names'))->decimal('total') !== '1') {
                throw new JetStreamApiException(0, 'Atomic subject route to target stream is not established');
            }
        }
        (new AtomicBatchTopology(fn (string $subject, array $payload): JsonObject => $this->read($subject, JsonObject::fromArray($payload)->toJson(), $connectionId, $deadline, $options)))->verify($serverId, $subjects, $feature);
    }

    private function read(string $subject, string $payload, int $connectionId, float $deadline, AtomicBatchOptions $options): JsonObject
    {
        $options->cancellation?->throwIfRequested();
        $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
        if ($remaining <= 0) {
            throw new JetStreamTimeoutException('Atomic batch deadline expired during target confirmation');
        }
        // PUB only: these API reads cannot create stream data, including on pre-atomic servers.
        $request  = new JetStreamControlPlaneRequestMessage($subject, $payload, '_INBOX.BATCH.CHECK.' . bin2hex(random_bytes(16)));
        $response = $this->client->requestOnceOnConnection($request, $connectionId, $remaining, $options->cancellation);
        if ($response instanceof StatusMessageInterface && ($status = $response->getStatus()) !== null) {
            throw new JetStreamApiException($status->code, 'Atomic target confirmation: ' . $status->description);
        }
        $raw   = new JsonObject($response->getPayload());
        $error = ApiError::fromResponse(json_decode($raw->toJson(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), 0, 'Atomic target confirmation failed');
        if ($error !== null) {
            throw $error;
        }
        return $raw;
    }
}
