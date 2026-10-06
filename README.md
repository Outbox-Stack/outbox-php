# getoutbox/outbox-php

PHP client for [Outbox Stack](https://outboxstack.app) transactional email. PHP 8.2+. Using Laravel? Install [`getoutbox/outbox-laravel`](https://github.com/Outbox-Stack/outbox-laravel) instead; it includes this client.

```sh
composer require getoutbox/outbox-php
```

Create a **Sending only** API key under Developers in the dashboard and set it as `OUTBOX_API_KEY`.

## Send

```php
use Outbox\Client;

$outbox = new Client(); // reads OUTBOX_API_KEY; base URL defaults to https://outboxstack.app

$result = $outbox->send([
    'from' => 'billing@your-verified-domain.com',
    'fromName' => 'Your app',
    'to' => 'Ada Obi <ada@example.com>',          // or a list
    'bcc' => 'archive@your-verified-domain.com',
    'replyTo' => 'support@your-verified-domain.com',
    'subject' => 'Your invoice',
    'html' => '<p>Invoice attached.</p>',
    'attachments' => [['path' => '/tmp/invoice.pdf']],   // or ['filename' => ..., 'content' => $rawBytes]
    'metadata' => ['invoice_id' => 'inv_123'],
], idempotencyKey: "invoice:{$invoice->id}");

$result['id'];          // first recipient's message id
$result['recipients'];  // one entry per recipient when there is more than one
```

Fields match the [API](https://outboxstack.app/openapi.json): `to`, `cc`, `bcc` (string or list, 50 recipients in total), `fromName`, `toName`, `replyTo`, `subject`, `html`, `text`, `template` + `data`, `headers` (20), `metadata` (10 strings), `attachments` (10 files, 10 MB). Attachment `content` is **raw bytes**; the client encodes it.

Each recipient becomes its own message with its own id, status and bounce tracking. `send()` returns once the message is queued, not delivered.

Also: `sendBatch($messages)` (1-100 messages; each may carry `idempotencyKey`), `getMessage($id)` and `listMessages(status: 'bounced', limit: 50)`.

## Retries and idempotency

Timeouts, connection errors, 408, 429 and 5xx are retried up to `maxRetries` times (default 2), honouring `Retry-After`. Every `send()` carries an idempotency key, generated when you don't pass one, so a retry never sends twice. Pass your own key, derived from the event that triggered the email, to stay safe across job retries too.

## Errors

```php
use Outbox\Exception\ApiException;
use Outbox\Exception\OutboxException;

try {
    $outbox->send($message);
} catch (ApiException $e) {
    $e->status();      // 422
    $e->errorCode();   // "domain_not_verified": branch on this, not the message
    $e->requestId();   // quote to support
} catch (OutboxException $e) {
    // network failure after retries, or invalid configuration
}
```

## Webhooks

```php
use Outbox\Webhook;
use Outbox\Exception\WebhookVerificationException;

try {
    $event = Webhook::verify(file_get_contents('php://input'), getallheaders(), getenv('OUTBOX_WEBHOOK_SECRET'));
} catch (WebhookVerificationException) {
    http_response_code(400);
    exit;
}
// $event['type'] === 'message.bounced', $event['data']['to'], $event['data']['metadata']
```

Pass the raw body exactly as received. Signatures follow Standard Webhooks; timestamps older than 5 minutes are rejected.

## Development

```sh
composer install
composer test
composer analyse
```
