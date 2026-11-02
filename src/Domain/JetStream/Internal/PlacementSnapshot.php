<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\{JsonObject,StreamInfo};

/** @internal Exact identity and typed placement comparison for rollback snapshots. */
final class PlacementSnapshot
{
    public static function sameInstance(StreamInfo $original, StreamInfo $other): void
    {
        $created = $original->getCreated()?->getUnixNanoseconds();
        $group   = $original->getCluster()?->raftGroup;
        if (
            $created === null || $other->getCreated()?->getUnixNanoseconds() !== $created
            || $original->getName() !== $other->getName() || $original->getDomain() !== $other->getDomain()
            || ($group !== null && $other->getCluster()?->raftGroup !== $group)
        ) {
            throw new JetStreamApiException(0, 'Rollback stream instance identity mismatch or unavailable: created/domain/raft_group');
        }
    }

    /** @return array<string,mixed> */
    public static function configurationPlacement(JsonObject|null $config): array
    {
        if ($config === null || !$config->has('placement')) {
            return [];
        }
        $object = $config->getObject('placement');
        if ($object === null) {
            throw new JetStreamApiException(0, 'Invalid config.placement: expected a JSON object');
        }
        return self::placement($object);
    }

    /** @return array<string,mixed> Object key order is irrelevant; JSON types and strings are exact. */
    public static function placement(JsonObject|null $object): array
    {
        if ($object === null) {
            return [];
        }
        // Decode without BIGINT_AS_STRING: a numeric token must never become a placement string.
        $value  = json_decode($object->toJson(), false, 512, JSON_THROW_ON_ERROR);
        $fields = new MetadataFields($object, 'placement');
        $fields->text('cluster');
        $fields->text('preferred');
        if (property_exists($value, 'tags')) {
            if (!is_array($value->tags) || !array_is_list($value->tags)) {
                throw new JetStreamApiException(0, 'Invalid placement.tags');
            }
            foreach ($value->tags as $tag) {
                if (!is_string($tag)) {
                    throw new JetStreamApiException(0, 'Invalid placement.tags: expected strings');
                }
            }
            sort($value->tags, SORT_STRING);
        }
        return get_object_vars($value) === [] ? [] : self::canonical($value);
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);
            foreach ($fields as $key => $item) {
                $fields[$key] = self::canonical($item);
            }
            // Tag objects so an object and an array cannot compare equal.
            return ['object' => $fields];
        }
        if (is_array($value)) {
            return ['array' => array_map(self::canonical(...), $value)];
        }
        return [get_debug_type($value) => $value];
    }
}
