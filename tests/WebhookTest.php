<?php

declare(strict_types=1);

namespace Outbox\Tests;

use Outbox\Exception\WebhookVerificationException;
use Outbox\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    // Shared vector: the Go signer and the JS verifier are tested against the same values.
    private const SECRET = 'whsec_b3V0Ym94LXNoYXJlZC10ZXN0LXZlY3Rvci1rZXktMzI=';
    private const BODY = '{"type":"message.delivered","created_at":"2026-10-06T16:00:00Z","data":{"message_id":"11111111-1111-4111-8111-111111111111","to":"ada@example.com","metadata":{"order_id":"42"}}}';
    private const HEADERS = ['Webhook-Id' => 'msg_2Lrf8Kq', 'webhook-timestamp' => ['1791300000'], 'webhook-signature' => 'v0,bogus v1,MCdWC/xo85YYQTUvU1t7W9dodNDOfd581ZfFQByu58U='];
    private const NOW = 1791300060;

    public function testAcceptsTheSharedVector(): void
    {
        $event = Webhook::verify(self::BODY, self::HEADERS, self::SECRET, now: self::NOW);
        self::assertSame('message.delivered', $event['type']);
        self::assertSame('42', $event['data']['metadata']['order_id']);
    }

    /** @return iterable<string, array{string, array<string, mixed>, string, int}> */
    public static function rejected(): iterable
    {
        yield 'tampered body' => [str_replace('42', '43', self::BODY), self::HEADERS, self::SECRET, self::NOW];
        yield 'stale timestamp' => [self::BODY, self::HEADERS, self::SECRET, self::NOW + 600];
        yield 'wrong secret' => [self::BODY, self::HEADERS, 'whsec_' . base64_encode('another-secret-another-secret!!'), self::NOW];
        yield 'missing headers' => [self::BODY, ['webhook-id' => 'x'], self::SECRET, self::NOW];
        yield 'non-numeric timestamp' => [self::BODY, ['webhook-timestamp' => '1e9'] + self::HEADERS, self::SECRET, self::NOW];
    }

    /** @param array<string, mixed> $headers */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejected')]
    public function testRejects(string $body, array $headers, string $secret, int $now): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhook::verify($body, $headers, $secret, now: $now);
    }
}
