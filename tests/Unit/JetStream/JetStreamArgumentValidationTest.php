<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumer;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumerFactory;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Tests\Support\InvalidNatsArguments;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamArgumentValidationTest extends TestCase
{
    #[DataProvider('invalidAdminNames')]
    public function testAdminRejectsNamesWithoutSending(string $operation, string $field, string $value): void
    {
        $transport = $this->createMock(JetStreamControlPlaneTransportInterface::class);
        $transport->expects(self::never())->method('request');
        $transport->expects(self::never())->method('publishRequest');
        $admin    = new JetStreamAdmin($transport);
        $stream   = $field === 'stream' ? $value : 'Orders-v1_2';
        $consumer = $field === 'consumer' ? $value : 'Worker-v1_2';

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage($field);

        match ($operation) {
            'createStream' => $admin->createStream(new StreamConfig($stream, ['orders.*'])),
            'getStreamInfo' => $admin->getStreamInfo($stream),
            'deleteStream' => $admin->deleteStream($stream),
            'createOrUpdateConsumer' => $admin->createOrUpdateConsumer($stream, new ConsumerConfig($consumer)),
            'getConsumerInfo' => $admin->getConsumerInfo($stream, $consumer),
            'deleteConsumer' => $admin->deleteConsumer($stream, $consumer),
        };
    }

    public static function invalidAdminNames(): iterable
    {
        foreach (['createStream', 'getStreamInfo', 'deleteStream', 'createOrUpdateConsumer', 'getConsumerInfo', 'deleteConsumer'] as $operation) {
            $fields = str_contains($operation, 'Consumer') ? ['stream', 'consumer'] : ['stream'];
            foreach ($fields as $field) {
                foreach (InvalidNatsArguments::resourceNames() as $label => $value) {
                    yield "$operation-$field-$label" => [$operation, $field, $value];
                }
            }
        }
    }

    #[DataProvider('invalidPullNames')]
    public function testPullRejectsNamesBeforeInboxSubscription(bool $factory, string $field, string $value): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('subscribe');
        $client->expects(self::never())->method('publish');
        $client->expects(self::never())->method('request');
        $transport = $this->createMock(JetStreamControlPlaneTransportInterface::class);
        $transport->method('getClient')->willReturn($client);
        $transport->expects(self::never())->method('publishRequest');
        $stream   = $field === 'stream' ? $value : 'Orders-v1_2';
        $consumer = $field === 'consumer' ? $value : 'Worker-v1_2';

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage($field);

        $pull = $factory
            ? (new JetStreamPullConsumerFactory($transport))->create($stream, $consumer)
            : new JetStreamPullConsumer($transport, $stream, $consumer);
        $pull->fetch(1, 100);
    }

    public static function invalidPullNames(): iterable
    {
        foreach ([false, true] as $factory) {
            foreach (['stream', 'consumer'] as $field) {
                foreach (InvalidNatsArguments::resourceNames() as $label => $value) {
                    yield ($factory ? 'factory' : 'constructor') . "-$field-$label" => [$factory, $field, $value];
                }
            }
        }
    }

    #[DataProvider('invalidTransportArguments')]
    public function testTransportRejectsArgumentsBeforeClientSideEffects(string $operation, string $field, string $value): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('request');
        $client->expects(self::never())->method('publish');
        $client->expects(self::never())->method('subscribe');
        $transport = new JetStreamControlPlaneTransport($client);
        $suffix    = $field === 'subject' ? $value : 'CONSUMER.MSG.NEXT.ORDERS.C1';
        $replyTo   = $field === 'replyTo' ? $value : '_INBOX.reply';

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage($field === 'subject' ? 'Subject' : 'Reply-To');

        if ($operation === 'request') {
            $transport->request($suffix, []);
            return;
        }

        $transport->publishRequest($suffix, [], $replyTo, $operation === 'headers' ? ['Test' => 'value'] : []);
    }

    public static function invalidTransportArguments(): iterable
    {
        foreach (['request', 'publish', 'headers'] as $operation) {
            foreach ($operation === 'request' ? ['subject'] : ['subject', 'replyTo'] as $field) {
                foreach (InvalidNatsArguments::commandTokens() as $label => $value) {
                    yield "$operation-$field-$label" => [$operation, $field, $value];
                }
            }
        }
    }

    #[DataProvider('extendedTransportAddresses')]
    public function testTransportPreservesExtendedJetStreamNames(string $operation, string $stream): void
    {
        $subject = '$JS.API.CONSUMER.INFO.' . $stream . '.WORKER@EU';
        $replyTo = $operation === 'request' ? 'JSREPLYreply1' : '_INBOX.reply@EU+1';
        $client  = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('subscribe');
        $matches = self::callback(static function (NatsProtocolMessageInterface $message) use ($subject, $replyTo, $operation): bool {
            self::assertSame($subject, $message->getSubject());
            self::assertSame($replyTo, $message->getReplyTo());
            $headers  = "NATS/1.0\r\nTest: value\r\n\r\n";
            $expected = $operation === 'headers'
                ? 'HPUB ' . $subject . ' ' . $replyTo . ' ' . strlen($headers) . ' ' . (strlen($headers) + 2) . "\r\n" . $headers . "{}\r\n"
                : 'PUB ' . $subject . ' ' . $replyTo . " 2\r\n{}\r\n";
            self::assertSame($expected, (string) $message);
            return true;
        });
        if ($operation === 'request') {
            $client->expects(self::once())->method('request')->with($matches)->willReturn(new MsgMessage('reply', 'sid1', '{"ok":true}'));
            $client->expects(self::never())->method('publish');
        } else {
            $client->expects(self::once())->method('publish')->with($matches);
            $client->expects(self::never())->method('request');
        }
        $helper = $this->createStub(SubscriptionIdHelperInterface::class);
        $helper->method('generateId')->willReturn('reply1');
        $transport = new JetStreamControlPlaneTransport($client, $helper);
        $suffix    = 'CONSUMER.INFO.' . $stream . '.WORKER@EU';

        if ($operation === 'request') {
            self::assertSame(['ok' => true], $transport->request($suffix, []));
        } else {
            $transport->publishRequest($suffix, [], $replyTo, $operation === 'headers' ? ['Test' => 'value'] : []);
        }
    }

    public static function extendedTransportAddresses(): iterable
    {
        foreach (['request', 'publish', 'headers'] as $operation) {
            foreach (['ORDERS@EU', 'ORDERS+EU'] as $stream) {
                yield "$operation-$stream" => [$operation, $stream];
            }
        }
    }

    public function testInvalidGeneratedReplyIsRejectedBeforeRequest(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('request');
        $client->expects(self::never())->method('subscribe');
        $helper = $this->createStub(SubscriptionIdHelperInterface::class);
        $helper->method('generateId')->willReturn("reply\n");
        $transport = new JetStreamControlPlaneTransport($client, $helper);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Reply-To');
        $transport->request('STREAM.INFO.ORDERS', []);
    }

    public function testNamesWithHyphenAndUnderscoreRemainSupported(): void
    {
        $transport = $this->createMock(JetStreamControlPlaneTransportInterface::class);
        $transport->method('getClient')->willReturn($this->createStub(ClientInterface::class));
        $transport->expects(self::once())->method('request')
            ->with('STREAM.INFO.Orders-v1_2', [], null)
            ->willReturn(['config' => ['name' => 'Orders-v1_2', 'subjects' => ['orders.*']]]);

        self::assertSame('Orders-v1_2', (new JetStreamAdmin($transport))->getStreamInfo('Orders-v1_2')->getName());
        self::assertInstanceOf(JetStreamPullConsumer::class, (new JetStreamPullConsumerFactory($transport))->create('Orders-v1_2', 'Worker-v1_2'));
    }
}
