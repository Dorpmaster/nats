<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Contracts;

/** Optional received wire size, before header normalization. */
interface WireBodySizeMessageInterface
{
    /** Header block plus payload bytes; command line and trailing CRLF are excluded. */
    public function getWireBodySize(): int;
}
