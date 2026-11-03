<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;

/** @internal Shared INFO/SNAPSHOT/persisted-config validation without bigint coercion. */
final class StreamSubjects
{
    /** @return list<string> */
    public static function read(JsonObject $config): array
    {
        $subjects = $config->has('subjects')
            ? json_decode($config->getRawValue('subjects'), false, 512, JSON_THROW_ON_ERROR)
            : [];
        if (!is_array($subjects) || !array_is_list($subjects) || array_filter($subjects, static fn (mixed $subject): bool => !is_string($subject)) !== []) {
            throw new JetStreamApiException(0, 'Invalid JetStream config.subjects: expected a JSON array of strings');
        }
        return $subjects;
    }
}
