<?php

declare(strict_types=1);

namespace Outbox;

use Outbox\Exception\WebhookVerificationException;

/** Verifies Standard Webhooks signatures on Outbox Stack webhooks. */
final class Webhook
{
    /**
     * Returns the decoded event, or throws WebhookVerificationException.
     * Pass the raw request body exactly as received, not re-encoded JSON.
     *
     * @param array<string, string|string[]> $headers
     * @return array{type: string, created_at: string, data: array<string, mixed>}
     */
    public static function verify(string $payload, array $headers, string $secret, int $toleranceSeconds = 300, ?int $now = null): array
    {
        $id = self::header($headers, 'webhook-id');
        $timestamp = self::header($headers, 'webhook-timestamp');
        $signatures = self::header($headers, 'webhook-signature');
        if ($id === null || $timestamp === null || $signatures === null) {
            throw new WebhookVerificationException('Missing webhook-id, webhook-timestamp or webhook-signature header');
        }
        if (!ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > $toleranceSeconds) {
            throw new WebhookVerificationException('Webhook timestamp is outside the allowed tolerance');
        }
        $key = base64_decode(preg_replace('/^whsec_/', '', $secret) ?? '', true);
        if ($key === false || $key === '') {
            throw new WebhookVerificationException('Webhook secret is not valid base64');
        }
        $expected = base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $payload, $key, true));
        foreach (explode(' ', $signatures) as $candidate) {
            $parts = explode(',', $candidate, 2);
            if (count($parts) === 2 && $parts[0] === 'v1' && hash_equals($expected, $parts[1])) {
                $event = json_decode($payload, true);
                if (!is_array($event)) {
                    throw new WebhookVerificationException('Webhook body is not a JSON object');
                }
                /** @var array{type: string, created_at: string, data: array<string, mixed>} $event */
                return $event;
            }
        }
        throw new WebhookVerificationException('No matching webhook signature');
    }

    /** @param array<string, string|string[]> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                $value = is_array($value) ? ($value[0] ?? null) : $value;
                return $value === null || $value === '' ? null : (string) $value;
            }
        }
        return null;
    }
}
