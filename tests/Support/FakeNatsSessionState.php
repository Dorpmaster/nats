<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\Socket\Socket;

final class FakeNatsSessionState
{
    /** @var list<string> */
    public array $commandNames = [];

    /** @var list<string> */
    public array $rawCommands = [];

    public bool $connectReceived = false;

    public bool $pingReceived = false;

    public bool $pongSent = false;

    public bool $ready = false;

    public bool $authorizationViolationSent = false;

    public string|null $latestInboxSid = null;

    public string|null $latestRequestReplyTo = null;

    public function __construct(public readonly Socket $socket)
    {
    }
}
