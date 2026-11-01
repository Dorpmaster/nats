<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;

final readonly class StreamInfo
{
    private NanoTimestamp|null $created;
    private NanoTimestamp|null $timestamp;
    private string|null $domain;
    private StreamStateInfo|null $state;
    private ClusterInfo|null $cluster;
    private JsonObject|null $metadata;

    /**
     * @param list<string> $subjects
     */
    public function __construct(
        private string $name,
        private array $subjects,
        private JsonObject|null $configuration = null,
        private JsonObject|null $response = null,
    ) {
        $fields          = new MetadataFields($response ?? new JsonObject('{}'), 'stream');
        $this->created   = $fields->timestamp('created');
        $this->timestamp = $fields->timestamp('ts');
        $this->domain    = $fields->text('domain');
        $this->state     = ($state = $fields->object('state')) === null ? null : new StreamStateInfo($state);
        $this->cluster   = ($cluster = $fields->object('cluster')) === null ? null : new ClusterInfo($cluster);
        $this->metadata  = (new MetadataFields($configuration ?? new JsonObject('{}'), 'config'))->object('metadata');
    }

    public function getConfiguration(): JsonObject|null
    {
        return $this->configuration;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function getSubjects(): array
    {
        return $this->subjects;
    }

    public function getCreated(): NanoTimestamp|null
    {
        return $this->created;
    }

    public function getTimestamp(): NanoTimestamp|null
    {
        return $this->timestamp;
    }

    public function getDomain(): string|null
    {
        return $this->domain;
    }

    public function getState(): StreamStateInfo|null
    {
        return $this->state;
    }

    public function getCluster(): ClusterInfo|null
    {
        return $this->cluster;
    }

    public function getMetadata(): JsonObject|null
    {
        return $this->metadata;
    }

    public function getResponse(): JsonObject|null
    {
        return $this->response;
    }
}
