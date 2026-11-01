<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Model\NanoTimestamp;
use InvalidArgumentException;

/** @internal Known response fields are validated before decoding numeric tokens. */
final readonly class MetadataFields
{
    public function __construct(private JsonObject $raw, private string $path)
    {
    }

    public function text(string $field): string|null
    {
        $token = $this->raw->getRawValue($field);
        if ($token === null || $token === 'null') {
            return null;
        }
        // JSON_BIGINT_AS_STRING also turns number tokens into PHP strings; inspect the wire type first.
        if (!str_starts_with($token, '"')) {
            $this->invalid($field);
        }
        return $this->raw->get($field);
    }

    public function boolean(string $field): bool|null
    {
        $value = $this->raw->get($field);
        if ($value !== null && !is_bool($value)) {
            $this->invalid($field);
        }
        return $value;
    }

    public function decimal(string $field, string $maximum = Decimal::UINT64_MAX): string|null
    {
        $value = $this->raw->getRawValue($field);
        if ($value === null || $value === 'null') {
            return null;
        }
        // JSON numbers cannot have leading zeroes. Quoted numbers, signs and exponents are not counters.
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            $this->invalid($field);
        }
        try {
            return Decimal::parse($value, $maximum);
        } catch (InvalidArgumentException) {
            $this->invalid($field);
        }
    }

    public function timestamp(string $field): NanoTimestamp|null
    {
        $value = $this->text($field);
        try {
            return $value === null ? null : NanoTimestamp::fromRfc3339($value);
        } catch (InvalidArgumentException) {
            $this->invalid($field);
        }
    }

    public function object(string $field): JsonObject|null
    {
        $value = $this->raw->getRawValue($field);
        if ($value === null || $value === 'null') {
            return null;
        }
        $object = $this->raw->getObject($field);
        if ($object === null) {
            $this->invalid($field);
        }
        return $object;
    }

    /** @return list<JsonObject>|null */
    public function objects(string $field): array|null
    {
        $raw = $this->raw->getRawValue($field);
        if ($raw === null || $raw === 'null') {
            return null;
        }
        if ($raw[0] !== '[') {
            $this->invalid($field);
        }
        // Split only top-level array members; keep each object's original numeric tokens.
        $objects = [];
        $start   = 1;
        $depth   = 0;
        $quoted  = false;
        $escaped = false;
        for ($index = 1, $length = strlen($raw); $index < $length; $index++) {
            $char = $raw[$index];
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
            } elseif (($char === ',' || $char === ']') && $depth === 0) {
                $token = trim(substr($raw, $start, $index - $start));
                if ($token !== '') {
                    if ($token[0] !== '{') {
                        $this->invalid($field);
                    }
                    $objects[] = new JsonObject($token);
                }
                $start = $index + 1;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
            }
        }
        return $objects;
    }

    private function invalid(string $field): never
    {
        throw new InvalidArgumentException('Invalid JetStream metadata field ' . $this->path . '.' . $field);
    }
}
