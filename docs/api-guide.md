# API guide

A practical guide to the public API under `/api/v1`: the first steps with curl against the demo, then idempotency, errors, pagination, rate limits, versions and webhooks. The running stack's [API reference](http://localhost:8600/docs/api) lists every operation, field and response; Scramble generates it from the code, and `make openapi` exports it to [`openapi.json`](../openapi.json).

Every command block on this page runs in `make docs-examples` against the demo, in order and in one shell, and prints what the block after it shows; the check fails when an example stops working. So do the two webhook signature examples, against the Standard Webhooks reference example and a real webhook from the demo. Each run adds a synthetic contact, an item and an invoice to Demo Comercial SAS; `make seed` starts the demo over.

## Before you start

Run `make demo`: the API listens on `http://localhost:8600/api/v1`. The examples need a shell, curl, grep, sed and cut, and nothing else: no jq. Every id is a 26-character lowercase ULID, and the API's JSON has no spaces, so `grep` and `cut` are enough to read one field.

<!-- example -->
```sh
API=http://localhost:8600/api/v1

# The first id of a JSON response.
first_id() { grep -o '"id":"[0-9a-z]\{26\}"' | head -n 1 | cut -d '"' -f 4; }

curl -s "$API/health"; echo
```

<!-- output -->
```text
{"status":"ok","checks":{"database":"ok","redis":"ok"}}
```

## First steps

### 1. Create an API key

A company's owner or admin creates API keys in a web app session: **Settings → Developers → Create key**, or the same request with curl. A session needs the CSRF cookie first, and its requests must come from the web app's origin, `http://localhost:8601`. The key comes back once, in `data.key`; the API keeps only its hash.

<!-- example -->
```sh
JAR=$(mktemp)   # the session's cookies
xsrf() { grep XSRF-TOKEN "$JAR" | cut -f 7 | sed 's/%3D/=/g; s/%2B/+/g; s/%2F/\//g'; }
session() {
  curl -s -b "$JAR" -c "$JAR" -H 'Origin: http://localhost:8601' -H "X-XSRF-TOKEN: $(xsrf)" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' "$@"
}

curl -s -b "$JAR" -c "$JAR" -o /dev/null -H 'Origin: http://localhost:8601' http://localhost:8600/sanctum/csrf-cookie
session "$API/auth/login" -d '{"email":"demo@example.com","password":"password"}' > me.json
COMPANY=$(grep -o '"id":"[0-9a-z]\{26\}","name":"Demo Comercial SAS"' me.json | cut -d '"' -f 4)

session "$API/api-keys" -H "X-Company-Id: $COMPANY" -d '{"name":"API guide","level":"read_write"}' > key.json
KEY=$(grep -o '"key":"dei_[A-Za-z0-9_]*"' key.json | cut -d '"' -f 4)
KEY_ID=$(first_id < key.json)
printf '%.16s…\n' "$KEY"
```

<!-- output -->
```text
<visible-part-of-the-key>…
```

A key has a level: `read_write`, or `read_only`, which may only read. It may also carry an expiry date (`expires_at`) and a rate limit of its own (`rate_limit_per_minute`).

### 2. Authenticate and choose the company

Send the key as a bearer token. A key belongs to one company, so it needs no `X-Company-Id`; if one is sent, it must be the key's own. A session works the other way: it must name its company in `X-Company-Id` on every request, and its user must be a member.

<!-- example -->
```sh
# The key's company.
curl -s -o /dev/null -w '%{http_code}\n' "$API/contacts?per_page=1" -H "Authorization: Bearer $KEY"
# No key.
curl -s -o /dev/null -w '%{http_code}\n' "$API/contacts?per_page=1"
# The key with another company of the same owner.
OTHER=$(grep -o '"id":"[0-9a-z]\{26\}","name":"Demo Servicios SAS"' me.json | cut -d '"' -f 4)
curl -s -o /dev/null -w '%{http_code}\n' "$API/contacts?per_page=1" -H "Authorization: Bearer $KEY" -H "X-Company-Id: $OTHER"
```

<!-- output -->
```text
200
401
403
```

### 3. Create a contact and an item

A contact needs its identification, its tax data and, in Colombia, its municipality's DANE code: 05001 is Medellín (`GET /reference/municipalities?department=05` lists Antioquia's). The API computes a NIT's check digit. Every company starts with the taxes IVA 19 %, IVA 5 %, IVA 0 % (exempt) and INC 8 %; an item names one of them by its id.

<!-- example -->
```sh
NIT="99$(date +%s | cut -c 3-10)"   # a synthetic NIT, new on every run
CONTACT=$(curl -s --fail-with-body "$API/contacts" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -d '{
  "organization_type": "1", "identification_type": "31", "identification_number": "'"$NIT"'",
  "legal_name": "Guide Customer SAS (synthetic)", "tax_scheme": "01", "fiscal_responsibilities": ["R-99-PN"],
  "email": "billing@guide-customer.example", "address": "Calle 10 # 43-20", "municipality_code": "05001"
}' | first_id)

TAX=$(curl -s "$API/taxes?per_page=100" -H "Authorization: Bearer $KEY" | grep -o '"id":"[0-9a-z]\{26\}","dian_code":"01","name":"IVA 19 %"' | cut -d '"' -f 4)
ITEM=$(curl -s --fail-with-body "$API/items" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -d '{
  "code": "GUIDE-'"$NIT"'", "name": "Consulting hour (synthetic)", "type": "service", "unit_code": "HUR",
  "unit_price": "150000.00", "tax_id": "'"$TAX"'"
}' | first_id)

echo "contact $CONTACT, item $ITEM"
```

<!-- output -->
```text
contact <id>, item <id>
```

Amounts and quantities travel as decimal strings, never as floating-point numbers.

### 4. Create and issue an invoice

A draft takes the customer, the payment form (`1` cash, `2` credit, which needs a `due_date`), the payment means (`10` cash, `42` bank transfer, among others) and its lines; a line with an item takes its code, price and tax from it. Issuing gives the invoice its number and its CUFE in one transaction, and starts the chain. The `Idempotency-Key` makes the issue safe to send again.

<!-- example -->
```sh
INVOICE=$(curl -s --fail-with-body "$API/invoices" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"contact_id":"'"$CONTACT"'","payment_form":"1","payment_means_code":"10","lines":[{"item_id":"'"$ITEM"'","quantity":"3"}]}' | first_id)

curl -s --fail-with-body -X POST "$API/invoices/$INVOICE/issue" -H "Authorization: Bearer $KEY" -H "Idempotency-Key: issue-$INVOICE" > issued.json
grep -o '"status":"issued"' issued.json | head -n 1
grep -o '"full_number":"[A-Z]*[0-9]*"' issued.json | head -n 1
grep -o '"cufe":"[0-9a-f]\{96\}"' issued.json | head -n 1 | cut -c 1-20
```

<!-- output -->
```text
"status":"issued"
"full_number":"<number>"
"cufe":"<first-hex-digits>
```

### 5. Follow its status

The chain runs in the background: the XML, its signature, the transmission to the DIAN simulator, its response, then the delivery to the customer. `electronic.status` goes `pending`, `generated`, `signed`, `sending`, then `accepted`, `rejected` or `failed`, and `electronic.final` turns true. `delivery.status` turns `delivered` once the AttachedDocument, the PDF and the email are done. `data.events` is the invoice timeline.

<!-- example -->
```sh
i=0
until curl -s "$API/invoices/$INVOICE" -H "Authorization: Bearer $KEY" > invoice.json && grep -q '"delivery":{"status":"delivered"' invoice.json; do
  i=$((i + 1)); [ "$i" -lt 60 ] || break; sleep 1
done
grep -o '"electronic":{"status":"[a-z]*","final":[a-z]*' invoice.json
grep -o '"delivery":{"status":"[a-z_]*"' invoice.json
grep -o '"type":"[a-z_.]*","payload"' invoice.json | cut -d '"' -f 4 | tr '\n' ' '; echo
```

<!-- output -->
```text
"electronic":{"status":"accepted","final":true
"delivery":{"status":"delivered"
created issued xml_generated xml_signed transmission_attempt accepted attached_document_built pdf_generated email_attempt
```

`GET /invoices?electronic_status=processing` lists the invoices still in the chain. An invoice that failed after its retries has `electronic.can_retry`; `POST /invoices/{id}/retry` starts it again, and a rejected one can be corrected and sent again with `POST /invoices/{id}/resend`.

### 6. Download the XML and the PDF

The signed XML exists once the invoice is signed, and the PDF, the AttachedDocument and the zip the customer received once it is delivered; before that, these return 409.

<!-- example -->
```sh
curl -s --fail-with-body "$API/invoices/$INVOICE/xml" -H "Authorization: Bearer $KEY" -o invoice.xml -w '%{http_code} %{content_type}\n'
curl -s --fail-with-body "$API/invoices/$INVOICE/pdf?download=1" -H "Authorization: Bearer $KEY" -o invoice.pdf -w '%{http_code} %{content_type}\n'
curl -s --fail-with-body "$API/invoices/$INVOICE/package" -H "Authorization: Bearer $KEY" -o invoice.zip -w '%{http_code} %{content_type}\n'
head -c 5 invoice.pdf; echo
```

<!-- output -->
```text
200 application/xml; charset=UTF-8
200 application/pdf
200 application/zip
%PDF-
```

`GET /invoices/{id}/dian-response` returns the simulator's ApplicationResponse, and `/attached-document` the AttachedDocument. Credit and debit notes have the same operations under `/credit-notes` and `/debit-notes`, and payments are recorded with `POST /payments`.

## Idempotency

Any `POST` may carry an `Idempotency-Key` header: 1 to 255 visible ASCII characters, kept 24 hours, and shared by all of the company's keys and sessions. The first request runs, and its response is stored with the key in the same database transaction as its effect. Sending it again replays that response, with `Idempotent-Replayed: true`, and runs nothing.

- **The same key with another request** (another method, path or body) returns 422: use a new key for a new request. Two bodies with the same JSON written another way are the same request.
- **The same key while the first request still runs** returns 409. Wait and send it again.
- **A response of 500 or more** is not stored: the effect is rolled back and the key is free again.
- **Any other response is stored, a 422 included.** After correcting a request, send it with a new key.

Creating API keys and webhook subscriptions, and rotating a webhook's secret, take no idempotency key: their responses carry a secret that must not be stored.

<!-- example -->
```sh
DRAFT='{"contact_id":"'"$CONTACT"'","lines":[{"item_id":"'"$ITEM"'","quantity":"1"}]}'
curl -s -D first.txt -o first.json "$API/invoices" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -H "Idempotency-Key: draft-$NIT" -d "$DRAFT"
curl -s -D again.txt -o again.json "$API/invoices" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -H "Idempotency-Key: draft-$NIT" -d "$DRAFT"
head -n 1 first.txt | tr -d '\r'
head -n 1 again.txt | tr -d '\r'
grep -i '^idempotent-replayed' again.txt | tr -d '\r'
[ "$(first_id < first.json)" = "$(first_id < again.json)" ] && echo "one draft"

# The same key with another body.
curl -s -o other.json -w '%{http_code}\n' "$API/invoices" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -H "Idempotency-Key: draft-$NIT" -d '{"lines":[]}'
grep -o '"detail":"[^"]*"' other.json

# Drafts can be deleted.
curl -s -o /dev/null -w '%{http_code}\n' -X DELETE "$API/invoices/$(first_id < first.json)" -H "Authorization: Bearer $KEY"
```

<!-- output -->
```text
HTTP/1.1 201 Created
HTTP/1.1 201 Created
Idempotent-Replayed: true
one draft
422
"detail":"This Idempotency-Key was already used with another request (method, path or body). Use a new key for a new request."
204
```

## Errors

Every error is a problem, `application/problem+json` (RFC 9457): `type` (always `about:blank`), `title`, `status`, `detail`, `instance` (the path), `request_id` and `correlation_id`. A validation error adds `errors`, a list of messages per field. With `APP_DEBUG` on, as in the local stack, a 500 adds the exception's class, file and line in `debug`; with it off, no internal detail reaches the client.

The messages come in English, or in Spanish when `Accept-Language` asks for it (`es`, `es-CO`, `es-419`): the `title`, the `detail` and the validation messages with the names of their fields. Any other language gets English, and the response names its language in `Content-Language`.

| Status | When |
| --- | --- |
| 400 | A session without a valid `X-Company-Id`, a malformed `Idempotency-Key`, or a sign-in from outside the web app's origin |
| 401 | No credentials, or a key that is unknown, revoked or expired |
| 403 | A `read_only` key that writes, another company's key, a key on a route reserved for the web app's session, or a role without the permission |
| 404 | A record that does not exist, or belongs to another company |
| 405 | A method the path does not take |
| 409 | A state that does not allow it: an invoice already issued, a file not ready yet, a retry not possible now, a record in use, an idempotency key still running |
| 419 | A session write without its CSRF token |
| 422 | Validation, the requirements of an issue, an idempotency key used with another request, a refused webhook URL |
| 429 | The rate limit, with `Retry-After` |
| 500, 503 | A failure on the server's side; `/health` returns 503 when a dependency is down |

<!-- example -->
```sh
curl -s -D problem.txt -o problem.json "$API/contacts" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -d '{"legal_name":"Incomplete Contact SAS"}'
tr -d '\r' < problem.txt | grep -i '^http\|^content-type\|^x-correlation-id'
grep -o '"title":"[^"]*","status":[0-9]*,"detail":"[^"]*"' problem.json
grep -o '"errors":{.*}' problem.json | grep -o '"[a-z_]*":\["' | cut -d '"' -f 2 | tr '\n' ' '; echo
```

<!-- output -->
```text
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/problem+json
X-Correlation-Id: <id>
"title":"Unprocessable Content","status":422,"detail":"The given data was invalid."
organization_type identification_type identification_number tax_scheme fiscal_responsibilities email municipality_code
```

### The correlation id

Every response carries `X-Correlation-Id`, and the problem repeats it. The same id follows the request into the chain, the DIAN calls, the email and the webhooks, and every log line of that journey carries it ([observability.md](observability.md#the-correlation-id)); a support request that quotes it can be traced from end to end ([customer-report.md](runbooks/customer-report.md)). A client may send its own, a ULID, to tie the journey to its own logs; any other value is replaced by a new id.

<!-- example -->
```sh
curl -s -o /dev/null -D - "$API/contacts?per_page=1" -H "Authorization: Bearer $KEY" -H 'X-Correlation-Id: 01kz9r2v0m4c7d8e5f6g9h3j1k' | tr -d '\r' | grep -i '^x-correlation-id'
curl -s -o /dev/null -D - "$API/contacts?per_page=1" -H "Authorization: Bearer $KEY" -H 'X-Correlation-Id: my-own-id' | tr -d '\r' | grep -i '^x-correlation-id' | grep -vc 'my-own-id'
```

<!-- output -->
```text
X-Correlation-Id: 01kz9r2v0m4c7d8e5f6g9h3j1k
1
```

## Pagination, rate limits and versions

**Pagination.** Lists are paged: `page` from 1, and `per_page` from 1 to 100, 25 by default. Each page carries `links` (`first`, `last`, `prev`, `next`) and `meta` (`current_page`, `last_page`, `per_page`, `total` and more). `search` filters by text, and each list has filters of its own, such as `status`, `electronic_status`, `contact_id`, `from` and `to` for documents. Each list's order is fixed: there is no sort parameter.

<!-- example -->
```sh
curl -s "$API/invoices?status=issued&per_page=2&page=1" -H "Authorization: Bearer $KEY" | grep -o '"current_page":1\|"per_page":2' | sort -u
```

<!-- output -->
```text
"current_page":1
"per_page":2
```

**Rate limits.** Each API key may send 120 requests a minute by default, or the limit it was created with, counted in fixed 60-second windows. Every response to a request with a key reports the limit in `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` (a Unix time), and in `RateLimit-Policy` and `RateLimit`. Above the limit, the API returns 429 with `Retry-After` in seconds. A replayed idempotent request counts too. The previews, the search and the sign-in have limits of their own.

<!-- example -->
```sh
curl -s -o /dev/null -D - "$API/contacts?per_page=1" -H "Authorization: Bearer $KEY" | tr -d '\r' | grep -i '^x-ratelimit-limit\|^ratelimit-policy'
```

<!-- output -->
```text
Ratelimit-Policy: "api-key";q=120;w=60
X-Ratelimit-Limit: 120
```

**Versions.** The version is in the path: `/api/v1` is the only one, and the reference names it 1.0.0. There is no deprecation header and no written policy for a v2 yet.

## Webhooks

A webhook subscription sends a company's events, signed, to an HTTPS endpoint. Owners and admins manage subscriptions with their session (**Settings → Developers**, or `/webhooks`): an API key cannot.

**Events.** `invoice.issued`, `invoice.accepted`, `invoice.rejected`, `invoice.failed`, `invoice.delivered`, `invoice.paid` and `invoice.voided`; `credit_note.issued`, `credit_note.accepted`, `credit_note.rejected` and `credit_note.delivered`, and the same four for `debit_note`; `payment.recorded` and `payment.voided`. `GET /webhooks/event-types` lists them, and `POST /webhooks/{id}/test` sends a `webhook.test`.

**The body** is JSON: `id` (`evt_` and a ULID), `type`, `created_at`, `company_id`, and `data.object`, the document or the payment as the API returns it. It is built once and sent byte for byte on every attempt.

**The headers** follow [Standard Webhooks](https://www.standardwebhooks.com): `webhook-id` (the event's id, the same on every retry, so a receiver can drop duplicates), `webhook-timestamp` (the attempt's time, in Unix seconds) and `webhook-signature`, plus `X-Correlation-Id`.

**The signature** is `v1,` followed by the base64-encoded HMAC-SHA256 of `{webhook-id}.{webhook-timestamp}.{body}`. Its key is the subscription's secret, `whsec_` and a base64 value, decoded. The header may hold several signatures separated by spaces. A receiver checks them in constant time and refuses a timestamp more than 5 minutes away from its clock; the sender itself applies no tolerance. The secret is shown once, when the subscription is created or its secret rotated, and a rotation replaces it at once.

**Retries.** An attempt waits 5 seconds for the endpoint, follows no redirect, and succeeds on a 2xx only. A failed one is retried after 2, 5, 15, 45 and 120 seconds, each plus up to 20 % of jitter: six attempts in all. Then the delivery fails, and `POST /webhook-deliveries/{id}/redeliver` starts it again with the same `webhook-id`. Delivery is at least once, in no particular order.

**Automatic deactivation.** After 20 failed deliveries in a row, the subscription turns itself off. Test events do not count, a success resets the count, and `PATCH /webhooks/{id}` with `{"is_active":true}` turns it on again.

**Production.** Endpoints must be `https` on port 443, the only port the functions may open toward the internet: any other gets a 422 when the subscription is created or changed, and is refused before each attempt ([limitations.md](limitations.md#api-and-webhooks)). The local stack also takes `http` and private addresses, so the demo can deliver to the simulator's webhook receiver.

The demo company is subscribed to that local receiver, which verifies each signature with the secrets the backend registers with it. This example subscribes once more, sends a test event, reads the receiver's verdict and deletes the subscription:

<!-- example -->
```sh
RECEIVER=$(session "$API/webhook-receiver" -H "X-Company-Id: $COMPANY" | grep -o '"url":"[^"]*"' | head -n 1 | cut -d '"' -f 4 | sed 's#\\/#/#g')
session "$API/webhooks" -H "X-Company-Id: $COMPANY" -d '{"url":"'"$RECEIVER"'","events":["invoice.accepted"],"description":"API guide"}' > hook.json
HOOK=$(first_id < hook.json)
grep -o '"secret":"whsec_' hook.json

session -X POST "$API/webhooks/$HOOK/test" -H "X-Company-Id: $COMPANY" -o test.json -w '%{http_code}\n'
EVENT=$(grep -o '"evt_[0-9a-z]\{26\}"' test.json | head -n 1 | tr -d '"')
i=0
until session "$API/webhook-receiver" -H "X-Company-Id: $COMPANY" | grep -o "\"webhook_id\":\"$EVENT\"[^}]*\"verification\":\"[a-z_]*\"" > verdict.txt; do
  i=$((i + 1)); [ "$i" -lt 30 ] || break; sleep 1
done
grep -o '"verification":"[a-z_]*"' verdict.txt

session -X DELETE "$API/webhooks/$HOOK" -H "X-Company-Id: $COMPANY" -o /dev/null -w '%{http_code}\n'
```

<!-- output -->
```text
"secret":"whsec_
201
"verification":"valid"
204
```

### Verifying a signature

Both functions take the secret, the three headers as received (with lowercase names) and the raw body, before any JSON parsing, and say whether the webhook is authentic and recent. `make docs-examples` runs both against the Standard Webhooks reference example and against a real webhook from the demo.

In Node.js, with `node:crypto` only:

<!-- example: node -->
```js
import { createHmac, timingSafeEqual } from 'node:crypto'

export function verifyWebhook(secret, headers, body, toleranceSeconds = 300) {
  const id = headers['webhook-id']
  const timestamp = headers['webhook-timestamp']
  const signatures = headers['webhook-signature']

  if (!id || !/^\d+$/.test(timestamp ?? '') || !signatures) {
    return false
  }

  if (Math.abs(Date.now() / 1000 - Number(timestamp)) > toleranceSeconds) {
    return false
  }

  const key = Buffer.from(secret.replace(/^whsec_/, ''), 'base64')
  const expected = createHmac('sha256', key).update(`${id}.${timestamp}.${body}`).digest()

  return signatures.split(' ').some((signature) => {
    const [version, value = ''] = signature.split(',', 2)
    const given = Buffer.from(value, 'base64')

    return version === 'v1' && given.length === expected.length && timingSafeEqual(given, expected)
  })
}
```

In PHP, without libraries:

<!-- example: php -->
```php
<?php

function verify_webhook(string $secret, array $headers, string $body, int $toleranceSeconds = 300): bool
{
    $id = $headers['webhook-id'] ?? '';
    $timestamp = $headers['webhook-timestamp'] ?? '';
    $signatures = $headers['webhook-signature'] ?? '';

    if ($id === '' || ! ctype_digit($timestamp) || $signatures === '') {
        return false;
    }

    if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
        return false;
    }

    $key = base64_decode(substr($secret, strlen('whsec_')), true);

    if ($key === false) {
        return false;
    }

    $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

    foreach (explode(' ', $signatures) as $signature) {
        [$version, $value] = array_pad(explode(',', $signature, 2), 2, '');

        if ($version === 'v1' && hash_equals($expected, $value)) {
            return true;
        }
    }

    return false;
}
```

## Cleaning up

A revoked key stops working at once:

<!-- example -->
```sh
session -X POST "$API/api-keys/$KEY_ID/revoke" -H "X-Company-Id: $COMPANY" -o /dev/null -w '%{http_code}\n'
curl -s -o /dev/null -w '%{http_code}\n' "$API/contacts?per_page=1" -H "Authorization: Bearer $KEY"
```

<!-- output -->
```text
200
401
```
