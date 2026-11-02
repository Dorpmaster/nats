<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use InvalidArgumentException;

final readonly class StreamConfigPatch
{
    /** @param list<string>|null $subjects */
    public function __construct(
        private array|null $subjects = null,
        private int|null $replicas = null,
        private bool|null $allowAtomicPublish = null,
        private bool|null $allowBatchPublish = null,
        private int|null $maxConsumers = null,
    ) {
        if ($subjects !== null) {
            if (!array_is_list($subjects)) {
                throw new InvalidArgumentException('Invalid subjects: expected a list');
            }
            foreach ($subjects as $subject) {
                if (!is_string($subject) || preg_match('/\A[^\s\p{Cc}]+\z/u', $subject) !== 1) {
                    throw new InvalidArgumentException('Invalid subjects: expected nonempty subject tokens');
                }
                $tokens = explode('.', $subject);
                foreach ($tokens as $index => $token) {
                    if (
                        $token === '' || (str_contains($token, '*') && $token !== '*')
                        || (str_contains($token, '>') && ($token !== '>' || $index !== count($tokens) - 1))
                    ) {
                        throw new InvalidArgumentException('Invalid subjects: invalid subscription pattern');
                    }
                }
            }
        }
        if ($replicas !== null && ($replicas < 1 || $replicas > 5)) {
            throw new InvalidArgumentException('Invalid replicas: expected 1 through 5');
        }
        if ($maxConsumers !== null && $maxConsumers < -1) {
            throw new InvalidArgumentException('Invalid maxConsumers: expected -1 or a nonnegative integer');
        }
    }

    /** @return array<string, mixed> */
    public function toRequestPayload(): array
    {
        return array_filter([
            'subjects' => $this->subjects,
            'num_replicas' => $this->replicas,
            'allow_atomic' => $this->allowAtomicPublish,
            'allow_batched' => $this->allowBatchPublish,
            'max_consumers' => $this->maxConsumers,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
