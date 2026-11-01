<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use JsonException;
use stdClass;

/** A validated JSON object retaining unmodified value tokens, including large numbers. */
final readonly class JsonObject
{
    public function __construct(private string $json)
    {
        if (!json_decode($json, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING) instanceof stdClass) {
            throw new JsonException('Expected a JSON object');
        }
        $this->members();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(json_encode($data === [] ? new stdClass() : $data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function toJson(): string
    {
        return $this->json;
    }

    /** @return array<string, mixed> Large integers are exposed as decimal strings. */
    public function toArray(): array
    {
        return json_decode($this->json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->members());
    }

    /** The original JSON value token, without decoding or numeric conversion. */
    public function getRawValue(string $field): string|null
    {
        return $this->members()[$field] ?? null;
    }

    public function get(string $field): mixed
    {
        $raw = $this->members()[$field] ?? null;
        return $raw === null ? null : json_decode($raw, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    public function getObject(string $field): self|null
    {
        $raw = $this->members()[$field] ?? null;
        return $raw !== null && str_starts_with(ltrim($raw), '{') ? new self($raw) : null;
    }

    /** @param array<string, mixed> $fields */
    public function withFields(array $fields): self
    {
        $members = $this->members();
        foreach ($fields as $key => $value) {
            $members[$key] = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        $parts = [];
        foreach ($members as $key => $raw) {
            $parts[] = json_encode((string) $key, JSON_THROW_ON_ERROR) . ':' . $raw;
        }
        return new self('{' . implode(',', $parts) . '}');
    }

    /** @return array<string, string> */
    private function members(): array
    {
        $json    = trim($this->json);
        $members = [];
        $start   = 1;
        $colon   = null;
        $depth   = 1;
        $quoted  = false;
        $escaped = false;
        for ($index = 1, $length = strlen($json); $index < $length; $index++) {
            $char = $json[$index];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $quoted = false;
                }
                continue;
            }
            if ($char === '"') {
                $quoted = true;
                continue;
            }
            if ($char === ':' && $depth === 1 && $colon === null) {
                $colon = $index;
            }
            if (($char === ',' && $depth === 1) || ($char === '}' && $depth === 1)) {
                if ($colon !== null) {
                    $key = json_decode(trim(substr($json, $start, $colon - $start)), false, 512, JSON_THROW_ON_ERROR);
                    if (array_key_exists($key, $members)) {
                        throw new JsonException('Duplicate JSON object key');
                    }
                    $members[$key] = trim(substr($json, $colon + 1, $index - $colon - 1));
                }
                $start = $index + 1;
                $colon = null;
            }
            if ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
            }
        }
        return $members;
    }
}
