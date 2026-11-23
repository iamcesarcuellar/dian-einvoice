<?php

use App\Support\ApiDocs\ApiConventionsExtension;
use App\Support\ApiDocs\ProblemDetailsExceptionExtension;
use App\Support\ApiDocs\SecureApiReference;

// Package defaults apply to every option not listed here. The routes, groups and security scheme
// are set in App\Support\ApiDocs\ApiDocumentation.
return [

    // Document the routes under /api/v1; the docs show paths relative to it.
    'api_path' => 'api/v1',

    'info' => [
        'version' => '1.0.0',
        'description' => <<<'MARKDOWN'
The API of dian-einvoice, the same one its web app uses: contacts, items, invoices, credit and debit notes, their electronic document chain (UBL 2.1 XML, CUFE or CUDE, XAdES signature, transmission and the response), the delivery to the customer, and payments. In this project the DIAN is always a local simulator and every email lands in a local mailbox: nothing reaches the DIAN.

## Authentication

Send a company API key in the `Authorization` header: `Authorization: Bearer dei_...`. Owners and admins create keys in **Settings → Developers**. A key is shown once and only its hash is stored; it can expire or be revoked.

- The company is the key's company: `X-Company-Id` is not needed and, when sent, must be that company (403 otherwise).
- `read_only` keys read everything below. `read_write` keys also write contacts, items, invoices, notes and payments, and run their actions (issue, retry, resend, send again, void a payment).
- No key manages users, API keys, webhooks, certificates, DIAN settings or the simulator: those belong to the web app session, and keys get 403.
- In timelines, the actor of an action done with a key is the key, with its name.

## Idempotency

Any `POST` may carry an `Idempotency-Key` header (1 to 255 visible ASCII characters, such as a UUID). Keys are per company. Each one is kept 24 hours with the response and a fingerprint of the request (method, path and body).

- The same key with the same request returns the first response, status and body, with `Idempotent-Replayed: true`, and runs nothing.
- The same key with another request returns **422**; while the first request is still running, **409**.
- Reserving a key is atomic, so two simultaneous requests with one key never both run. A 5xx response is not kept: the request can be repeated with the same key.

## Rate limits

Each key may make 120 requests per minute, unless it has a limit of its own. Every response to a key carries `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` (Unix time), plus the IETF draft's `RateLimit-Policy` and `RateLimit`. Above the limit, the API returns **429** with `Retry-After` in seconds.

## Pagination and search

Lists return `data`, `links` and `meta` (`current_page`, `last_page`, `per_page`, `total`). Use `?page=` and `?per_page=` (1 to 100, 25 by default); most lists also take `?search=` and their own filters.

## Money and dates

Amounts, quantities and rates are decimal strings, such as `"1190000.00"`, never floats. Document dates are Colombian dates (`YYYY-MM-DD`); instants are ISO 8601 in UTC.

## Errors

Every error is `application/problem+json` (RFC 9457): `type` (always `about:blank`), `title`, `status`, `detail`, `instance`, `request_id` and `correlation_id`. Validation errors (422) add `errors`, each field's messages. Every response carries an `X-Request-Id` header and an `X-Correlation-Id` header with the same ids, which the logs also hold: quote them when asking about a request. 401: no valid credentials. 403: the key level or the role does not allow it. 404: not found in the company. 409: the state does not allow it, such as issuing twice. 503: a local service, such as the DIAN simulator, is not responding.

## Correlation

Every response carries `X-Correlation-Id`, the id of the journey the request begins: a lowercase ULID, such as `01jcz9w6n8s0k3q4r5t6v7w8x9`. What the request sets in motion carries the same id: the steps of the electronic document chain, the call to the DIAN, the email to the customer and every webhook delivery (in its own `X-Correlation-Id` header). To tie an integration's logs to the platform's, send your own id in `X-Correlation-Id`: it is kept when it is a ULID, in upper or lower case, and replaced by a new one otherwise. Problems return it as `correlation_id`.

## Webhooks

Owners and admins subscribe URLs in **Settings → Developers**: in production, an https URL on port 443, the default port of https, the only one deliveries leave on. Another scheme or port is refused when the subscription is created or changed, with a **422** whose `errors.url` says why, and before every attempt of a subscription saved before. They subscribe to these events: `invoice.issued`, `invoice.accepted`, `invoice.rejected`, `invoice.failed`, `invoice.delivered`, `invoice.paid`, `invoice.voided`; `credit_note.issued`, `credit_note.accepted`, `credit_note.rejected`, `credit_note.delivered`, and the same four for `debit_note`; `payment.recorded` and `payment.voided`. "Send test event" sends `webhook.test`.

The body is `{"id": "evt_...", "type": "invoice.accepted", "created_at": "...", "company_id": "...", "data": {"object": {...}}}`, where `data.object` is the same resource this API returns for that document or payment.

Deliveries follow the [Standard Webhooks](https://www.standardwebhooks.com) specification: the headers `webhook-id` (the event id, the same on every retry), `webhook-timestamp` (Unix seconds) and `webhook-signature` (`v1,` and the base64 HMAC-SHA256 of `{webhook-id}.{webhook-timestamp}.{body}` with the secret, the base64 after `whsec_` decoded), plus `X-Correlation-Id`, the journey of the request that caused the event. Verify the signature over the raw body, reject old timestamps, and respond with a 2xx within 5 seconds.

Delivery is at least once and in no particular order: deduplicate by `webhook-id`. A failed attempt is retried after 2, 5, 15, 45 and 120 seconds; then the delivery fails, and it can be sent again by hand. A subscription is switched off after 20 failed deliveries in a row.
MARKDOWN,
    ],

    // A public reference: anyone who reaches the API can read it. Its page gets a Content-Security-Policy of its
    // own and Subresource Integrity on the files of its renderer (resources/http/security-headers.json).
    'middleware' => [SecureApiReference::class],

    'extensions' => [
        ProblemDetailsExceptionExtension::class,
        ApiConventionsExtension::class,
    ],

];
