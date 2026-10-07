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

Content is either `subject` with `html` and/or `text`, or a `template` (with optional `data`). Anything else (no content, a subject without a body, or a template mixed with subject/html/text) throws `OutboxException` before any request is made.

Also: `sendBatch($messages)` (1-100 messages; each may carry `idempotencyKey`) and `getMessage($id)`.

## Find messages

```php
$recent = $outbox->listMessages(status: 'bounced', limit: 100);   // newest 100

foreach ($outbox->iterateMessages(status: 'bounced', to: '@example.com') as $m) {
    echo $m['id'], ' ', $m['to_email'], ' ', $m['created_at'], PHP_EOL;   // every match, a page at a time
}
```

`listMessages()` returns one page (up to 200). `iterateMessages()` follows the cursor for you; to page by hand, call `listMessagesPage()` and pass its `next` back as `after` until it is `null`. `to` matches recipients containing the text, case-insensitively.

## Cancel a message

```php
try {
    $outbox->cancelMessage($id);
} catch (ApiException $e) {
    if ($e->errorCode() !== 'not_cancellable') {
        throw $e;
    }
    // already sending or finished
}
```

Only a **queued** message can be cancelled. Each recipient of a multi-recipient send has its own id. Cancelling twice succeeds, so retries are safe, and managed-sending usage for a cancelled message is refunded.

## Suppressions

Suppressed addresses are never sent to. Bounces, complaints and unsubscribes are added automatically; you can add your own.

```php
$outbox->addSuppression('ada@example.com');                     // reason "manual"
foreach ($outbox->iterateSuppressions() as $s) {
    echo $s['email'], ' ', $s['reason'], PHP_EOL;
}
$outbox->removeSuppression('ada@example.com');                  // manual suppressions only
```

Suppression calls need a **full-access** API key; sending keys get `forbidden`. Removing a bounce, complaint or unsubscribe gives `conflict`. `removeSuppression()` is not retried automatically: a retry after a lost response would report `not_found` for an address that was removed.

## Retries and idempotency

Timeouts, connection errors, 408, 429, 5xx and 2xx responses with an unreadable body are retried up to `maxRetries` times (default 2), honouring `Retry-After` (seconds or an HTTP date). 4xx errors are never retried. Every `send()` carries an idempotency key, generated when you don't pass one, so a retry never sends twice. Pass your own key, derived from the event that triggered the email, to stay safe across job retries too.

## Errors

```php
use Outbox\Exception\ApiException;
use Outbox\Exception\ConnectionException;
use Outbox\Exception\OutboxException;

try {
    $outbox->send($message);
} catch (ApiException $e) {
    $e->status();      // 422
    $e->errorCode();   // "domain_not_verified": branch on this, not the message
    $e->requestId();   // quote to support
} catch (ConnectionException $e) {
    $e->errorCode();       // "timeout" or "connection_error": the request may have reached us
    $e->idempotencyKey();  // retry with this key and nothing is sent twice
} catch (OutboxException $e) {
    // invalid message or configuration
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
