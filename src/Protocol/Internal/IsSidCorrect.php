<?php

namespace Dorpmaster\Nats\Protocol\Internal;

trait IsSidCorrect
{
    private function isSidCorrect(string $subject): bool
    {
        return preg_match('/\A[[:alnum:]]+\z/', $subject) === 1;
    }
}
