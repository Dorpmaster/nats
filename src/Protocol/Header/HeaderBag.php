<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Header;

use ArrayIterator;
use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use InvalidArgumentException;
use Traversable;

final class HeaderBag implements HeaderBugInterface
{
    private array $headers = [];

    public function __construct(array $headers = [])
    {
        foreach ($headers as $key => $values) {
            $this->set($key, $values);
        }
    }

    public function names(): array
    {
        return array_keys($this->headers);
    }

    public function get(string $key, string|null $default = null): string|array|null
    {
        if (!array_key_exists($key, $this->headers)) {
            return $default;
        }

        $value = $this->headers[$key];

        return count($value) === 1 ? reset($value) : $value;
    }

    public function set(string $key, string|array $value, bool $replace = true): void
    {
        if (!$this->isKeyValid($key)) {
            throw new InvalidArgumentException(sprintf('Invalid header name: %s', $key));
        }

        if (!$this->isValueValid($value)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid header "%s" value: %s',
                $key,
                json_encode($value),
            ));
        }

        if (is_string($value)) {
            $value = [$value];
        }

        $values = array_map(
            static function (string $item): string {
                return trim(str_replace(["\r\n", "\n", "\r"], '', $item));
            },
            array_values($value)
        );

        if (true === $replace || !isset($this->headers[$key])) {
            $this->headers[$key] = $values;
        } else {
            $this->headers[$key] = array_merge($this->headers[$key], $values);
        }
    }

    /**
     * Header's name must contain only printable ASCII characters (i.e., characters that have values
     * between 33. and 126., decimal, except colon).
     */
    private function isKeyValid(string $key): bool
    {
        return preg_match('/^[\x21-9;-~]+$/', $key) === 1;
    }

    /**
     * Header's value must contain only ASCII characters.
     */
    private function isValueValid(string|array $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_string($item)) {
                    return false;
                }

                if (!$this->isValueValid($item)) {
                    return false;
                }
            }

            return true;
        }

        return preg_match('/^[[:ascii:]]+$/', $value) === 1;
    }

    private function valueToString(string $key, string $value): string
    {
        return sprintf('%s: %s', $key, $value);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->headers);
    }

    public function count(): int
    {
        return count($this->headers);
    }

    public function __toString(): string
    {
        $items = [
            'NATS/1.0',
        ];

        foreach ($this->headers as $key => $values) {
            $items = array_merge(
                $items,
                array_map(
                    fn(string $item): string => $this->valueToString($key, $item),
                    $values,
                ),
            );
        }

        return implode(NatsProtocolMessageInterface::DELIMITER, $items);
    }
}
