<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use InvalidArgumentException;

final readonly class StreamConfigPatch
{
    private StreamSource|false|null $mirror;
    /** @param list<string>|null $subjects
     * @param list<StreamSource>|null $sources
     * @param StreamSource|false|null $mirror Null omits the field; false explicitly removes the mirror.
     */
    public function __construct(
        private array|null $subjects = null,
        private int|null $replicas = null,
        private bool|null $allowAtomicPublish = null,
        private bool|null $allowBatchPublish = null,
        private int|null $maxConsumers = null,
        private bool|null $allowMessageSchedules = null,
        private bool|null $allowMessageTtl = null,
        mixed $mirror = null,
        private array|null $sources = null,
    ) {
        if ($mirror !== null && $mirror !== false && !$mirror instanceof StreamSource) {
            throw new InvalidArgumentException('Invalid mirror: expected StreamSource, false (remove), or null (omit)');
        }
        $this->mirror = $mirror;
        if ($sources !== null) {
            StreamSource::validateList($sources);
        }
        if ($mirror instanceof StreamSource && (($subjects !== null && $subjects !== []) || ($sources !== null && $sources !== []))) {
            throw new InvalidArgumentException('Invalid mirror: subjects and sources must be empty');
        }
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
        $payload = array_filter([
            'subjects' => $this->subjects,
            'num_replicas' => $this->replicas,
            'allow_atomic' => $this->allowAtomicPublish,
            'allow_batched' => $this->allowBatchPublish,
            'max_consumers' => $this->maxConsumers,
            'allow_msg_schedules' => $this->allowMessageSchedules,
            'allow_msg_ttl' => $this->allowMessageTtl,
        ], static fn (mixed $value): bool => $value !== null);
        if ($this->mirror !== null) {
            $payload['mirror'] = $this->mirror === false ? null : $this->mirror->toRequestPayload();
        }
        if ($this->sources !== null) {
            $payload['sources'] = array_map(static fn (StreamSource $source): array => $source->toRequestPayload(), $this->sources);
        }
        return $payload;
    }
}
