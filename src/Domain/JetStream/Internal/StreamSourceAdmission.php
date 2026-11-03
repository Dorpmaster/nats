<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Dorpmaster\Nats\Domain\JetStream\Capability\Feature;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilities;
use Dorpmaster\Nats\Domain\JetStream\Capability\Support;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamSourceConsumer;

/** Read-only admission. Does not create/reset/delete an application's source consumer. */
final readonly class StreamSourceAdmission
{
    /** @param \Closure(string,array): (array|JsonObject) $request
     * @param \Closure(string,string,array): (array|JsonObject)|null $originRequest
     */
    public function __construct(private JetStreamCapabilities $capabilities, private \Closure $request, private \Closure|null $originRequest = null)
    {
    }

    /** INFO is a snapshot, not an instruction to remove a link. */
    public static function validateSnapshot(JsonObject $configuration, JsonObject|null $response = null): void
    {
        foreach (['mirror', 'sources'] as $field) {
            if ($configuration->getRawValue($field) === 'null') {
                throw new JetStreamApiException(0, 'Invalid JetStream config.' . $field . ': null is not a valid INFO snapshot');
            }
        }
        $fields = new MetadataFields($configuration, 'config');
        $fields->object('mirror');
        $configuredSources = $fields->objects('sources');
        if ($response !== null) {
            $observed         = new MetadataFields($response, 'stream');
            $configuredMirror = $configuration->getObject('mirror');
            if ($observed->object('mirror') !== null && ($configuredMirror === null || $configuredMirror->toArray() === [])) {
                throw new JetStreamApiException(0, 'Invalid JetStream config.mirror: active mirror is missing from INFO configuration');
            }
            self::validateObservedSources($configuredSources ?? [], $observed->objects('sources') ?? []);
        }
    }

    /** @param list<JsonObject> $configured @param list<JsonObject> $observed */
    private static function validateObservedSources(array $configured, array $observed): void
    {
        if ($observed === []) {
            return;
        }
        try {
            $candidates = array_map(self::sourceIdentity(...), $configured);
            $identities = array_map(self::sourceIdentity(...), $observed);
            $assigned   = [];
            foreach (array_keys($identities) as $index) {
                $visited = [];
                if (!self::assignSource($index, $identities, $candidates, $assigned, $visited)) {
                    throw new JetStreamApiException(0, 'Invalid JetStream config.sources: observed source is missing or has a different origin/filter');
                }
            }
        } catch (\InvalidArgumentException $error) {
            throw new JetStreamApiException(0, 'Invalid JetStream config.sources: ' . $error->getMessage(), $error);
        }
    }

    /** Only identity metadata participates; lag, sequence and list order do not. @return array<string, mixed> */
    private static function sourceIdentity(JsonObject $source): array
    {
        $fields = new MetadataFields($source, 'sources');
        $name   = $fields->text('name');
        if ($name === null || $name === '') {
            throw new \InvalidArgumentException('Missing source name');
        }
        $identity = ['name' => $name];
        if ($source->has('external')) {
            $external = $fields->object('external');
            $api      = $external === null ? null : (new MetadataFields($external, 'sources.external'))->text('api');
            if ($api === null || preg_match('/\A[^\s\p{Cc}.*>]+(?:\.[^\s\p{Cc}.*>]+)*\.?\z/u', $api) !== 1) {
                throw new \InvalidArgumentException('Invalid source external.api');
            }
            $identity['api'] = str_ends_with($api, '.') ? substr($api, 0, -1) : $api;
        }
        if ($source->has('filter_subject')) {
            $identity['filter'] = $fields->text('filter_subject') ?? throw new \InvalidArgumentException('Invalid source filter_subject');
        }
        if ($source->has('subject_transforms')) {
            $transforms             = $fields->objects('subject_transforms') ?? throw new \InvalidArgumentException('Invalid source subject_transforms');
            $identity['transforms'] = array_map(static function (JsonObject $transform): array {
                $fields = new MetadataFields($transform, 'sources.subject_transforms');
                return [$fields->text('src') ?? '', $fields->text('dest') ?? ''];
            }, $transforms);
        }
        return $identity;
    }

    /** Match each observation once, including when older servers omit optional identity fields.
     * @param list<array<string, mixed>> $observed @param list<array<string, mixed>> $configured
     * @param array<int, int> $assigned @param array<int, bool> $visited
     */
    private static function assignSource(int $index, array $observed, array $configured, array &$assigned, array &$visited): bool
    {
        foreach ($configured as $candidate => $identity) {
            if (isset($visited[$candidate])) {
                continue;
            }
            foreach ($observed[$index] as $field => $value) {
                $default = match ($field) {
                    'filter' => '', 'transforms' => [], default => null
                };
                if (($identity[$field] ?? $default) !== $value) {
                    continue 2;
                }
            }
            $visited[$candidate] = true;
            if (!isset($assigned[$candidate]) || self::assignSource($assigned[$candidate], $observed, $configured, $assigned, $visited)) {
                $assigned[$candidate] = $index;
                return true;
            }
        }
        return false;
    }

    public function requireSupport(): void
    {
        $this->requireSupportAt($this->capabilities->apiPrefix);
    }

    private function requireSupportAt(string $prefix): void
    {
        if ($prefix === $this->capabilities->apiPrefix && $this->capabilities->check(Feature::DURABLE_SOURCING)->support === Support::UNSUPPORTED) {
            throw new JetStreamApiException(0, 'Durable sourcing and flow_control require JetStream API level 4');
        }
        try {
            $info  = $this->read('INFO', $prefix);
            $api   = $info->getObject('api');
            $level = $api === null ? null : (new MetadataFields($api, 'api'))->decimal('level', (string) PHP_INT_MAX);
            if ($info->get('type') !== 'io.nats.jetstream.api.v1.account_info_response' || $level === null || Decimal::compare($level, '4') < 0) {
                throw new JetStreamApiException(0, 'Durable sourcing target API level 4 is not established');
            }
        } catch (\InvalidArgumentException | \JsonException $error) {
            throw new JetStreamApiException(0, 'Invalid durable sourcing API metadata: ' . $error->getMessage(), $error);
        }
    }

    public function verify(JsonObject $configuration, JsonObject|null $previous = null): void
    {
        try {
            $fields = new MetadataFields($configuration, 'config');
            // Preserve unchanged snapshots verbatim; do not turn a limit patch into link admission.
            $sources = $previous !== null && $previous->getRawValue('sources') === $configuration->getRawValue('sources') ? [] : ($fields->objects('sources') ?? []);
            $mirror  = $previous !== null && $previous->getRawValue('mirror') === $configuration->getRawValue('mirror') ? null : $fields->object('mirror');
            if ($mirror !== null) {
                $sources[] = $mirror;
            }
            $links = [];
            $seen  = [];
            foreach ($sources as $source) {
                $sourceFields = new MetadataFields($source, 'source');
                $origin       = $sourceFields->text('name');
                ResourceName::validate($origin ?? '', 'source.name');
                $prefix   = $this->originPrefix($source);
                $consumer = $sourceFields->object('consumer');
                if ($consumer === null) {
                    // UNKNOWN is not permission: choose the mode from the actual origin retention.
                    $config       = $this->read('STREAM.INFO.' . $origin, $prefix)->getObject('config');
                    $originFields = new MetadataFields($config ?? new JsonObject('{}'), 'source.config');
                    $retention    = $originFields->text('retention');
                    if ($originFields->text('name') !== $origin || !in_array($retention, ['limits', 'interest', 'workqueue'], true)) {
                        throw new JetStreamApiException(0, 'Source origin identity/retention is not established');
                    }
                    if ($retention !== 'limits') {
                        $this->requireSupport();
                        if ($prefix !== $this->capabilities->apiPrefix) {
                            $this->requireSupportAt($prefix);
                        }
                    }
                    continue;
                }
                $consumerFields = new MetadataFields($consumer, 'source.consumer');
                $reference      = new StreamSourceConsumer($consumerFields->text('name') ?? '', $consumerFields->text('deliver_subject') ?? '');
                foreach (['opt_start_seq', 'opt_start_time', 'filter_subject'] as $field) {
                    if ($source->has($field)) {
                        throw new JetStreamApiException(0, 'Invalid source.consumer: cannot combine with ' . $field);
                    }
                }
                $key = $origin . '\0' . $reference->name;
                if (isset($seen[$key])) {
                    throw new JetStreamApiException(0, 'Duplicate source stream and consumer pair');
                }
                $seen[$key] = true;
                $links[]    = [$origin, $reference, $prefix];
            }
            if ($links === []) {
                return;
            }
            $this->requireSupport();
            foreach ($links as [$origin, $reference, $prefix]) {
                if ($prefix !== $this->capabilities->apiPrefix) {
                    $this->requireSupportAt($prefix);
                }
                $raw    = $this->read('CONSUMER.INFO.' . $origin . '.' . $reference->name, $prefix);
                $info   = new MetadataFields($raw, 'consumer');
                $config = $info->object('config');
                $cf     = new MetadataFields($config ?? new JsonObject('{}'), 'consumer.config');
                if ($info->text('stream_name') !== $origin || $info->text('name') !== $reference->name || $cf->text('durable_name') !== $reference->name || ($config?->has('name') && $cf->text('name') !== $reference->name)) {
                    throw new JetStreamApiException(0, 'Source consumer identity is not established');
                }
                if ($cf->text('deliver_subject') !== $reference->deliverSubject || $cf->text('ack_policy') !== 'flow_control' || $cf->boolean('flow_control') !== true || $cf->decimal('idle_heartbeat') !== '1000000000') {
                    throw new JetStreamApiException(0, 'Source consumer requires matching deliver_subject, flow_control ACK and 1s heartbeat');
                }
            }
        } catch (\InvalidArgumentException | \JsonException $error) {
            throw new JetStreamApiException(0, 'Invalid source/mirror configuration: ' . $error->getMessage(), $error);
        }
    }

    private function originPrefix(JsonObject $source): string
    {
        if (!$source->has('external')) {
            return $this->capabilities->apiPrefix;
        }
        $external = (new MetadataFields($source, 'source'))->object('external');
        $prefix   = (new MetadataFields($external ?? new JsonObject('{}'), 'source.external'))->text('api');
        if ($prefix === null || preg_match('/\A[^\s\p{Cc}.*>]+(?:\.[^\s\p{Cc}.*>]+)*\.?\z/u', $prefix) !== 1) {
            throw new JetStreamApiException(0, 'Invalid source.external.api prefix');
        }
        return str_ends_with($prefix, '.') ? substr($prefix, 0, -1) : $prefix;
    }

    private function read(string $suffix, string|null $prefix = null): JsonObject
    {
        if ($prefix === null || $prefix === $this->capabilities->apiPrefix) {
            $response = ($this->request)($suffix, []);
        } else {
            if ($this->originRequest === null) {
                throw new JetStreamApiException(0, 'Cannot verify external source API with this transport');
            }
            $response = ($this->originRequest)($prefix, $suffix, []);
        }
        $raw   = $response instanceof JsonObject ? $response : LegacyMetadataResponse::fromArray($response);
        $error = ApiError::fromResponse(json_decode($raw->toJson(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), 0, 'Source admission failed');
        if ($error !== null) {
            throw $error;
        }
        return $raw;
    }
}
