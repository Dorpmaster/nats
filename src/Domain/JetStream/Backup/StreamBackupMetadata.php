<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Backup;

use Dorpmaster\Nats\Domain\JetStream\Internal\{MetadataFields,ResourceName};
use Dorpmaster\Nats\Domain\JetStream\Model\{JsonObject,StreamStateInfo};
use InvalidArgumentException;

/** Immutable persisted companion to an opaque completed V2 archive. */
final readonly class StreamBackupMetadata
{
    public string $stream;
    public JsonObject $config;
    public JsonObject $state;
    public int $archiveBytes;
    private function __construct(private JsonObject $raw)
    {
        $fields = new MetadataFields($raw, 'backup');
        if ($fields->text('type') !== 'dorpmaster.nats.backup.v2') {
            throw new InvalidArgumentException('Invalid backup metadata type');
        }
        $this->stream = $fields->text('stream') ?? '';
        ResourceName::validate($this->stream, 'stream');
        $this->config = $fields->object('config') ?? throw new InvalidArgumentException('Invalid backup.config');
        $this->state  = $fields->object('state') ?? throw new InvalidArgumentException('Invalid backup.state');
        if ((new MetadataFields($this->config, 'config'))->text('name') !== $this->stream) {
            throw new InvalidArgumentException('Invalid backup config.name identity');
        }
        \Dorpmaster\Nats\Domain\JetStream\Internal\StreamSubjects::read($this->config);
        $count = $fields->decimal('archive_bytes', (string) PHP_INT_MAX);
        if ($count === null || $count === '0') {
            throw new InvalidArgumentException('Invalid backup.archive_bytes');
        }
        $this->archiveBytes = (int) $count;
        $state              = new MetadataFields($this->state, 'state');
        foreach (['messages','bytes','first_seq','last_seq','consumer_count'] as $field) {
            if ($state->decimal($field) === null) {
                throw new InvalidArgumentException('Missing backup.state.' . $field);
            }
        }
        new StreamStateInfo($this->state);
    }
    public static function fromJson(string $json): self
    {
        return new self(new JsonObject($json));
    }
    public static function completed(string $stream, JsonObject $config, JsonObject $state, int $archiveBytes): self
    {
        return self::fromJson('{"type":"dorpmaster.nats.backup.v2","stream":' . json_encode($stream, JSON_THROW_ON_ERROR) . ',"config":' . $config->toJson() . ',"state":' . $state->toJson() . ',"archive_bytes":' . $archiveBytes . '}');
    }
    public function toJson(): string
    {
        return $this->raw->toJson();
    }
}
