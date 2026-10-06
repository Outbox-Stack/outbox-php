<?php

declare(strict_types=1);

namespace Outbox\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Outbox\Client;
use Outbox\Exception\ApiException;
use Outbox\Exception\OutboxException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ClientTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    /** @param list<Response|\Throwable> $responses */
    private function client(array $responses, int $maxRetries = 2): Client
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new Client(apiKey: 'test-key', baseUrl: 'https://mail.example.com', maxRetries: $maxRetries, http: new Guzzle(['handler' => $stack]), sleep: static function (int $ms): void {
        });
    }

    private static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function testSendForwardsFieldsAndEncodesAttachments(): void
    {
        $client = $this->client([self::json(['id' => self::ID, 'status' => 'queued'], 202)]);
        $result = $client->send([
            'from' => 'app@x.com', 'to' => ['Ada <a@x.com>'], 'cc' => 'b@x.com', 'replyTo' => 'help@x.com',
            'subject' => 'Invoice', 'text' => 'Attached', 'metadata' => ['order_id' => '42'],
            'attachments' => [['filename' => 'a.txt', 'content' => 'hi']],
        ], idempotencyKey: 'inv-1');
        self::assertSame('queued', $result['status']);
        $request = $this->history[0]['request'];
        self::assertSame('https://mail.example.com/v1/messages', (string) $request->getUri());
        self::assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        self::assertSame('inv-1', $request->getHeaderLine('Idempotency-Key'));
        self::assertStringStartsWith('outbox-php/', $request->getHeaderLine('User-Agent'));
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame('aGk=', $body['attachments'][0]['content']);
        self::assertSame(['Ada <a@x.com>'], $body['to']);
        self::assertSame(['order_id' => '42'], $body['metadata']);
    }

    public function testAttachmentPathIsReadAndNamed(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'outbox');
        file_put_contents($file, '%PDF');
        $client = $this->client([self::json(['id' => self::ID, 'status' => 'queued'], 202)]);
        $client->send(['from' => 'a@x.com', 'to' => 'b@x.com', 'subject' => 's', 'text' => 't', 'attachments' => [['path' => $file, 'filename' => 'invoice.pdf']]]);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        unlink($file);
        self::assertSame(['filename' => 'invoice.pdf', 'content' => base64_encode('%PDF')], $body['attachments'][0]);
    }

    public function testSendRetriesWithOneStableGeneratedKey(): void
    {
        $client = $this->client([
            new ConnectException('down', new Request('POST', '/')),
            self::json(['error' => 'busy', 'code' => 'unavailable'], 503, ['Retry-After' => '0']),
            self::json(['id' => self::ID, 'status' => 'queued'], 202),
        ]);
        self::assertSame(self::ID, $client->send(['from' => 'a@x.com', 'to' => 'b@x.com', 'subject' => 's', 'text' => 't'])['id']);
        $keys = array_map(static fn (array $h) => $h['request']->getHeaderLine('Idempotency-Key'), $this->history);
        self::assertCount(3, $keys);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $keys[0]);
        self::assertSame([$keys[0]], array_values(array_unique($keys)));
    }

    public function testApiErrorsCarryCodeRequestIdAndAreNotRetried(): void
    {
        $client = $this->client([self::json(['error' => 'verify example.com', 'code' => 'domain_not_verified', 'request_id' => 'req_1'], 422)]);
        try {
            $client->send(['from' => 'a@x.com', 'to' => 'b@x.com', 'subject' => 's', 'text' => 't']);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status());
            self::assertSame('domain_not_verified', $e->errorCode());
            self::assertSame('req_1', $e->requestId());
            self::assertSame('verify example.com', $e->getMessage());
        }
        self::assertCount(1, $this->history);
    }

    public function testRetriesStopAtMaxRetries(): void
    {
        $client = $this->client([self::json(['error' => 'slow down'], 429, ['Retry-After' => '0']), self::json(['error' => 'slow down', 'code' => 'rate_limited'], 429, ['Retry-After' => '0'])], maxRetries: 1);
        $this->expectException(ApiException::class);
        try {
            $client->getMessage(self::ID);
        } finally {
            self::assertCount(2, $this->history);
        }
    }

    public function testBatchRetriesOnlyWhenEveryItemHasAKey(): void
    {
        $item = ['from' => 'a@x.com', 'to' => 'b@x.com', 'subject' => 's', 'text' => 't'];
        $client = $this->client([self::json(['error' => 'down'], 502, ['Retry-After' => '0'])]);
        try {
            $client->sendBatch([$item, $item + ['idempotencyKey' => 'k']]);
        } catch (ApiException) {
        }
        self::assertCount(1, $this->history);
        $client = $this->client(array_fill(0, 3, self::json(['error' => 'down'], 502, ['Retry-After' => '0'])));
        try {
            $client->sendBatch([$item + ['idempotencyKey' => 'k']]);
        } catch (ApiException) {
        }
        self::assertCount(3, $this->history);
    }

    public function testListMessagesBuildsQuery(): void
    {
        $client = $this->client([self::json(['messages' => [['id' => self::ID]]])]);
        self::assertSame([['id' => self::ID]], $client->listMessages(status: 'bounced', limit: 10));
        self::assertSame('https://mail.example.com/v1/messages?status=bounced&limit=10', (string) $this->history[0]['request']->getUri());
    }

    public function testConfigurationIsValidatedBeforeAnyRequest(): void
    {
        foreach (['http://remote.example', 'https://u:p@remote.example', 'https://remote.example/api'] as $bad) {
            try {
                new Client(apiKey: 'k', baseUrl: $bad);
                self::fail("accepted $bad");
            } catch (OutboxException) {
                self::addToAssertionCount(1);
            }
        }
        putenv('OUTBOX_API_KEY');
        try {
            new Client();
            self::fail('accepted a missing key');
        } catch (OutboxException $e) {
            self::assertStringContainsString('OUTBOX_API_KEY', $e->getMessage());
        }
        putenv('OUTBOX_API_KEY=from-env');
        self::assertInstanceOf(Client::class, new Client());
        putenv('OUTBOX_API_KEY');
        $this->expectException(OutboxException::class);
        (new Client(apiKey: 'k'))->getMessage('../api-keys');
    }
}
