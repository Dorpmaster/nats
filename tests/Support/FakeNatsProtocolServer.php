<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\CancelledException;
use Amp\Socket\Socket;
use Amp\Socket\ServerSocket;
use Dorpmaster\Nats\Protocol\MsgMessage;

use function Amp\async;
use function Amp\delay;
use function Amp\Socket\listen;

final class FakeNatsProtocolServer
{
    private const string INFO_PAYLOAD = '{"server_id":"fake","server_name":"fake-nats","version":"1","go":"go","host":"127.0.0.1","port":4222,"headers":true,"max_payload":1048576,"proto":1}';

    private ServerSocket $server;

    /** @var list<FakeNatsSessionState> */
    private array $sessions = [];

    /** @var list<Socket> */
    private array $clients = [];

    private bool $stopped = false;

    public function __construct()
    {
        $this->server = listen('127.0.0.1:0');

        async(function (): void {
            while (!$this->stopped && ($client = $this->server->accept()) !== null) {
                $session          = new FakeNatsSessionState($client);
                $this->sessions[] = $session;
                $this->clients[]  = $client;

                $client->write(sprintf("INFO %s\r\n", self::INFO_PAYLOAD));

                async(fn() => $this->handleClient($session));
            }
        });
    }

    public function host(): string
    {
        return '127.0.0.1';
    }

    public function port(): int
    {
        return $this->server->getAddress()->getPort();
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;

        foreach ($this->clients as $client) {
            $client->close();
        }

        $this->server->close();
    }

    public function awaitSessionCount(int $expectedCount, float $timeoutSeconds = 1.0): void
    {
        $this->await(
            static fn(array $sessions): bool => count($sessions) >= $expectedCount,
            $timeoutSeconds,
            sprintf('Expected at least %d accepted sessions', $expectedCount),
        );
    }

    public function awaitCommandCount(int $sessionIndex, int $expectedCount, float $timeoutSeconds = 1.0): void
    {
        $this->await(
            fn(array $sessions): bool => isset($sessions[$sessionIndex]) && count($sessions[$sessionIndex]->commandNames) >= $expectedCount,
            $timeoutSeconds,
            sprintf('Expected at least %d commands for session %d', $expectedCount, $sessionIndex),
        );
    }

    /** @return list<string> */
    public function commandNames(int $sessionIndex): array
    {
        return $this->sessions[$sessionIndex]->commandNames ?? [];
    }

    /** @return list<string> */
    public function rawCommands(int $sessionIndex): array
    {
        return $this->sessions[$sessionIndex]->rawCommands ?? [];
    }

    public function authorizationViolationSent(int $sessionIndex): bool
    {
        return $this->sessions[$sessionIndex]->authorizationViolationSent ?? false;
    }

    public function anyAuthorizationViolationSent(): bool
    {
        foreach ($this->sessions as $session) {
            if ($session->authorizationViolationSent) {
                return true;
            }
        }

        return false;
    }

    public function releasePong(int $sessionIndex): void
    {
        $session = $this->sessions[$sessionIndex] ?? null;
        if ($session === null || $session->pongSent || !$session->pingReceived) {
            return;
        }

        $session->socket->write("PONG\r\n");
        $session->pongSent = true;
        $session->ready    = true;
    }

    public function closeSession(int $sessionIndex): void
    {
        $session = $this->sessions[$sessionIndex] ?? null;
        $session?->socket->close();
    }

    public function replyToLatestRequest(int $sessionIndex, string $payload = 'response'): void
    {
        $session = $this->sessions[$sessionIndex] ?? null;
        if ($session === null || $session->latestRequestReplyTo === null || $session->latestInboxSid === null) {
            throw new \RuntimeException(sprintf('Cannot reply to request for session %d', $sessionIndex));
        }

        $session->socket->write((string) new MsgMessage(
            $session->latestRequestReplyTo,
            $session->latestInboxSid,
            $payload,
        ));
    }

    private function handleClient(FakeNatsSessionState $session): void
    {
        $buffer              = '';
        $pendingPayloadBytes = null;
        $pendingCommandName  = null;
        $pendingCommandLine  = null;

        try {
            while (($chunk = $session->socket->read()) !== null) {
                $buffer .= $chunk;

                while (true) {
                    if ($pendingPayloadBytes !== null) {
                        if (strlen($buffer) < $pendingPayloadBytes + 2) {
                            break;
                        }

                        $payload = substr($buffer, 0, $pendingPayloadBytes);
                        $suffix  = substr($buffer, $pendingPayloadBytes, 2);
                        if ($suffix !== "\r\n") {
                            throw new \RuntimeException('Malformed client payload delimiter');
                        }

                        $buffer = (string) substr($buffer, $pendingPayloadBytes + 2);
                        $this->recordCommand($session, (string) $pendingCommandName, (string) $pendingCommandLine);
                        $this->handleApplicationCommand($session, (string) $pendingCommandName, (string) $pendingCommandLine, $payload);

                        $pendingPayloadBytes = null;
                        $pendingCommandName  = null;
                        $pendingCommandLine  = null;

                        continue;
                    }

                    $delimiterPos = strpos($buffer, "\r\n");
                    if ($delimiterPos === false) {
                        break;
                    }

                    $line   = substr($buffer, 0, $delimiterPos);
                    $buffer = (string) substr($buffer, $delimiterPos + 2);

                    if ($line === '') {
                        continue;
                    }

                    $commandName = strtoupper((string) strtok($line, ' '));
                    if (in_array($commandName, ['PUB', 'HPUB'], true)) {
                        $metadata = preg_split('/\s+/', trim($line)) ?: [];
                        $size     = (int) ($metadata[array_key_last($metadata)] ?? 0);

                        $pendingPayloadBytes = $size;
                        $pendingCommandName  = $commandName;
                        $pendingCommandLine  = $line;

                        continue;
                    }

                    $this->recordCommand($session, $commandName, $line);

                    if ($commandName === 'CONNECT') {
                        $session->connectReceived = true;

                        continue;
                    }

                    if ($commandName === 'PING') {
                        $session->pingReceived = true;

                        continue;
                    }

                    if ($commandName === 'SUB') {
                        $this->handleApplicationCommand($session, $commandName, $line, '');

                        continue;
                    }
                }
            }
        } catch (CancelledException) {
            // Ignore test shutdown cancellation.
        } finally {
            $session->socket->close();
        }
    }

    private function handleApplicationCommand(
        FakeNatsSessionState $session,
        string $commandName,
        string $commandLine,
        string $payload,
    ): void {
        if (in_array($commandName, ['SUB', 'PUB', 'HPUB'], true) && !$session->ready) {
            $session->authorizationViolationSent = true;
            $session->socket->write("-ERR 'Authorization Violation'\r\n");
            $session->socket->close();

            return;
        }

        if ($commandName === 'SUB') {
            $parts = preg_split('/\s+/', trim($commandLine)) ?: [];
            $sid   = $parts[array_key_last($parts)] ?? null;
            if (is_string($sid)) {
                $session->latestInboxSid = $sid;
            }

            return;
        }

        if (in_array($commandName, ['PUB', 'HPUB'], true)) {
            $parts = preg_split('/\s+/', trim($commandLine)) ?: [];

            if ($commandName === 'PUB') {
                if (count($parts) === 4) {
                    $session->latestRequestReplyTo = $parts[2];
                }

                return;
            }

            if (count($parts) === 5) {
                $session->latestRequestReplyTo = $parts[2];
            }
        }
    }

    private function recordCommand(FakeNatsSessionState $session, string $commandName, string $rawCommand): void
    {
        $session->commandNames[] = $commandName;
        $session->rawCommands[]  = $rawCommand;
    }

    /**
     * @param \Closure(list<FakeNatsSessionState>):bool $condition
     */
    private function await(\Closure $condition, float $timeoutSeconds, string $message): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($condition($this->sessions)) {
                return;
            }

            delay(0.01);
        }

        throw new \RuntimeException($message . $this->describeSessions());
    }

    private function describeSessions(): string
    {
        $description = [];

        foreach ($this->sessions as $index => $session) {
            $description[] = sprintf(
                ' session=%d commands=%s err=%s',
                $index,
                json_encode($session->commandNames, JSON_THROW_ON_ERROR),
                $session->authorizationViolationSent ? 'yes' : 'no',
            );
        }

        return $description === [] ? '' : ' Got:' . implode(';', $description);
    }
}
