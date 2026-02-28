<?php

namespace Dorpmaster\Nats\Protocol\Internal;

trait IsSubjectCorrect
{
    private function isSubjectCorrect(string $subject): bool
    {
        return
            (preg_match('/^[[:alnum:]]+[[:alnum:].*]*>?$/', $subject) === 1) &&
            (preg_match('/[[:alnum:]]>/', $subject) !== 1) &&
            (preg_match('/\.\./', $subject) !== 1) &&
            (preg_match('/\*\*/', $subject) !== 1) &&
            (preg_match('/[[:alnum:]]\*[[:alnum:]]/', $subject) !== 1) &&
            (preg_match('/[[:alnum:]]>/', $subject) !== 1)
        ;
    }
}
