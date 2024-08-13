<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Contracts;

interface HeaderBugInterface extends \IteratorAggregate, \Countable, \Stringable
{
    /**
     * @return string|list<string>
     */
    public function get(string $key, string|null $default = null): string|array|null;

    /**
     * @throws \InvalidArgumentException
     */
    public function set(string $key, string|array $value, bool $replace = true): void;

    /**
     * @return list<string>
     */
    public function names(): array;
}
