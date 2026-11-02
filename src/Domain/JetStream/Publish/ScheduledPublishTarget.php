<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Amp\Cancellation;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Internal\ApiError;
use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneRequestMessage;
use Dorpmaster\Nats\Protocol\Contracts\StatusMessageInterface;

/** @internal Certifies all three addresses before any schedule definition is written. */
final readonly class ScheduledPublishTarget
{
    public function __construct(private ConnectionBoundRequestClientInterface $client, private int $connectionId, private float $deadline, private Cancellation $scope)
    {
    }
    public function verify(ScheduleOptions $options, string $definition, string|null $serverId): void
    {
        $info   = $this->read('$JS.API.STREAM.INFO.' . $options->stream, []);
        $config = $info->getObject('config');
        if ($info->get('type') !== 'io.nats.jetstream.api.v1.stream_info_response' || $config === null || (new MetadataFields($config, 'config'))->text('name') !== $options->stream) {
            throw new JetStreamApiException(0, 'Schedule target stream identity is not established');
        }
        // NATS omits an inactive transform. Any present token is active or untrustworthy, including null/arrays.
        if ($config->has('subject_transform')) {
            throw new JetStreamApiException(0, 'Schedule config.subject_transform cannot be certified');
        }
        $fields = new MetadataFields($config, 'config');
        if ($config->has('no_ack') && $config->getRawValue('no_ack') !== 'false') {
            throw new JetStreamApiException(0, 'Schedule config.no_ack must be false or absent to receive a definition PubAck');
        }
        $level = ($metadata = $config->getObject('metadata')) === null ? null : (new MetadataFields($metadata, 'metadata'))->text('_nats.level');
        if ($level === null || preg_match('/\A[0-9]{1,9}\z/', $level) !== 1 || (int) $level < $options->feature()->minimumApiLevel()) {
            throw new JetStreamApiException(0, 'Schedule target API level is not established');
        }
        if ($fields->boolean('allow_msg_schedules') !== true) {
            throw new JetStreamApiException(0, 'Schedule target requires allow_msg_schedules=true');
        }
        if ($options->ttlSeconds !== null && $fields->boolean('allow_msg_ttl') !== true) {
            throw new JetStreamApiException(0, 'Schedule TTL requires allow_msg_ttl=true');
        }
        $subjects = [$definition, $options->target];
        if ($options->source !== null) {
            $subjects[] = $options->source;
        }
        foreach ($subjects as $subject) {
            $names = $this->read('$JS.API.STREAM.NAMES', ['subject' => $subject]);
            if ($names->get('type') !== 'io.nats.jetstream.api.v1.stream_names_response' || $names->get('streams') !== [$options->stream] || (new MetadataFields($names, 'names'))->decimal('total') !== '1') {
                throw new JetStreamApiException(0, 'Schedule subject route to the target stream is not established');
            }
        }
        (new AtomicBatchTopology(fn (string $subject, array $payload): JsonObject => $this->read($subject, $payload)))->verify($serverId, $subjects, $options->feature());
    }
    private function read(string $subject, array $payload): JsonObject
    {
        $this->scope->throwIfRequested();
        $remaining = ($this->deadline - hrtime(true)) / 1000000000;
        if ($remaining <= 0) {
            throw new JetStreamTimeoutException('Scheduled publication admission timed out');
        }
        $reply = $this->client->requestOnceOnConnection(new JetStreamControlPlaneRequestMessage($subject, JsonObject::fromArray($payload)->toJson(), '_INBOX.SCHEDULE.CHECK.' . bin2hex(random_bytes(16))), $this->connectionId, $remaining, $this->scope);
        if ($reply instanceof StatusMessageInterface && ($status = $reply->getStatus()) !== null) {
            throw new JetStreamApiException($status->code, $status->description);
        }
        $raw   = new JsonObject($reply->getPayload());
        $error = ApiError::fromResponse(json_decode($raw->toJson(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), 0, 'Schedule target confirmation failed');
        if ($error !== null) {
            throw $error;
        }
        return $raw;
    }
}
