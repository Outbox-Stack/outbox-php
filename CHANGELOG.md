# Changelog

## 0.2.0

Matches the Node SDK 0.4.0. Needs the API release with message paging and cancel (live on outboxstack.app).

**Added**
- Message paging: `listMessagesPage()` returns `['messages' => …, 'next' => …]`, `iterateMessages()` walks every page, and all three take `to` (recipient contains) and `after`.
- `cancelMessage($id)` stops a queued message; `errorCode()` is `not_cancellable` once sending has started. Safe to retry.
- Suppressions: `listSuppressions()`, `listSuppressionsPage()`, `iterateSuppressions()`, `addSuppression($email)`, `removeSuppression($email)` (not retried automatically). Need a full-access key.
- `ConnectionException` (`errorCode()` `timeout` or `connection_error`) when the API can't be reached after retries.
- Every exception from a request carries `idempotencyKey()`, so a failed send can be retried without sending twice.
- `ApiException::retryAfterMs()`.

**Changed**
- A 2xx response with an empty or unreadable body is retried with the same idempotency key; if it persists, `errorCode()` is `invalid_response`.
- `Retry-After` given as an HTTP date is honoured, not just seconds.
- `send()` and `sendBatch()` reject messages without valid content before any request: no content, a subject without a body, or a template mixed with `subject`/`html`/`text` (the template would have replaced them silently).

## 0.1.0

First release: `send`, `sendBatch`, `getMessage`, `listMessages`, attachments, retries with idempotency keys, and `Webhook::verify`.
