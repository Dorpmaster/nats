<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Message\AckObserverInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAckerInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageInterface;
use Psr\Log\LoggerInterface;

final readonly class LoggingJetStreamMessageAcker implements JetStreamMessageAckerInterface
{
    public function __construct(
        private JetStreamMessageAckerInterface $acker,
        private LoggerInterface $logger,
    ) {
    }

    public function observe(JetStreamMessageInterface $message, AckObserverInterface $observer): void
    {
        $this->acker->observe($message, $observer);
    }

    public function ack(JetStreamMessageInterface $message): void
    {
        $this->sendWithLogging($message, '+ACK', static function (JetStreamMessageAckerInterface $acker, JetStreamMessageInterface $message): void {
            $acker->ack($message);
        });
    }

    public function nak(JetStreamMessageInterface $message, int|null $delayMs = null): void
    {
        $this->sendWithLogging($message, '-NAK', static function (JetStreamMessageAckerInterface $acker, JetStreamMessageInterface $message) use ($delayMs): void {
            $acker->nak($message, $delayMs);
        }, $delayMs);
    }

    public function term(JetStreamMessageInterface $message): void
    {
        $this->sendWithLogging($message, '+TERM', static function (JetStreamMessageAckerInterface $acker, JetStreamMessageInterface $message): void {
            $acker->term($message);
        });
    }

    public function inProgress(JetStreamMessageInterface $message): void
    {
        $this->sendWithLogging($message, '+WPI', static function (JetStreamMessageAckerInterface $acker, JetStreamMessageInterface $message): void {
            $acker->inProgress($message);
        });
    }

    /** @param \Closure(JetStreamMessageAckerInterface, JetStreamMessageInterface): void $sender */
    private function sendWithLogging(
        JetStreamMessageInterface $message,
        string $kind,
        \Closure $sender,
        int|null $delayMs = null,
    ): void {
        $this->logger->debug('js.ack.send', [
            'kind' => $kind,
            'has_reply' => $message->getReplyTo() !== null && $message->getReplyTo() !== '',
            'delay_ms' => $delayMs,
        ]);

        try {
            $sender($this->acker, $message);
        } catch (JetStreamApiException $exception) {
            if (str_contains($exception->getMessage(), 'Missing reply subject for ack')) {
                $this->logger->error('js.ack.missing_reply', [
                    'kind' => $kind,
                    'subject' => $message->getSubject(),
                ]);
            }

            throw $exception;
        }
    }
}
