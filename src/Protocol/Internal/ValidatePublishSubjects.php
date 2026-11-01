<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Internal;

use InvalidArgumentException;

trait ValidatePublishSubjects
{
    use IsSubjectCorrect;

    private function validatePublishSubjects(string $subject, string|null $replyTo = null): void
    {
        if (!$this->isSubjectCorrect($subject)) {
            throw new InvalidArgumentException(sprintf('Invalid Subject: %s', $subject));
        }

        if ($replyTo !== null && !$this->isSubjectCorrect($replyTo)) {
            throw new InvalidArgumentException(sprintf('Invalid Reply-To: %s', $replyTo));
        }
    }
}
