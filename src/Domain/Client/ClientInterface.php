<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Amp\CancelledException;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;

interface ClientInterface
{
    /**
     * @throws CancelledException
     * @throws ConnectionException
     */
    public function connect(): void;

    /**
     * @throws CancelledException
     * @throws ConnectionException
     */
    public function disconnect(): void;

    public function waitForTermination(): void;
}
