<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use stdClass;

/** @internal Recover known object positions after an array-only transport loses JSON shapes. */
final class LegacyMetadataResponse
{
    /** @param array<string, mixed> $response */
    public static function fromArray(array $response): JsonObject
    {
        return new JsonObject(json_encode(self::object($response, ''), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function object(mixed $value, string $path): mixed
    {
        if (!is_array($value) && !$value instanceof stdClass) {
            return $value;
        }
        $fields = match ($path) {
            '' => ['config', 'state', 'delivered', 'ack_floor', 'cluster', 'mirror', 'api', 'error'],
            'config' => ['metadata', 'mirror'],
            'mirror', 'sources.*' => ['error', 'external'],
            'config.mirror', 'config.sources.*' => ['consumer', 'external'],
            'cluster' => ['desired'],
            'cluster.desired' => ['origin', 'status'],
            'cluster.desired.origin' => ['placement'],
            default => [],
        };
        // Build fresh objects rather than mutate objects/references owned by a legacy transport.
        $object = new stdClass();
        foreach ($value as $field => $item) {
            $childPath = $path === '' ? (string) $field : $path . '.' . $field;
            if (in_array($field, $fields, true)) {
                $item = self::object($item, $childPath);
            } elseif ($field === 'sources' && ($path === '' || $path === 'config') && is_array($item)) {
                $item = array_map(static fn (mixed $source): mixed => self::object($source, $childPath . '.*'), $item);
            } elseif ($field === 'replicas' && ($path === 'cluster' || $path === 'cluster.desired') && is_array($item)) {
                $item = array_map(static fn (mixed $peer): mixed => self::object($peer, $childPath), $item);
            }
            $object->{(string) $field} = $item;
        }
        return $object;
    }
}
