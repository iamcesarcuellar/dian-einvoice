# A customer reports an error

**English** · [Español](../es/runbooks/customer-report.md)

A procedure with no alarm. A company writes in to say that something failed: an invoice that did not go out, or an API response that was an error.

Every API response carries `X-Correlation-Id`. Every `application/problem+json` error also carries it as `correlation_id`, next to `request_id`. With that id, one query shows every line of the journey, from the request to its webhooks, in every function (see the correlation id section of docs/observability.md).

1. **Ask for the correlation id, and only for it**: the response's `X-Correlation-Id`, the error's `correlation_id`, or the request's time and route. Never ask for a password, an API key, a certificate or their customers' data. The id is enough, and it is not a secret.

   An integration should log the id with every response. The web app does not show it to its users (docs/limitations.md). A web app user can find it in the browser's developer tools, in the response to the request that failed. Otherwise, they can give the time and what they did.
2. **Follow the journey**: every line of the request and of the jobs it started, in order, with each line's function, event, outcome and message.
   - Emulator: `make cloud-follow ID=<correlation id> HOURS=24`
   - AWS: the saved query `dian-einvoice/prod/follow-a-journey` in CloudWatch Logs Insights, with the id in place of `REPLACE-WITH-THE-CORRELATION-ID`, over the days since the request.
3. **Read what happened.** The first line is the request (`http.request`, its route and status). It names the actor (`user:<id>` or `api-key:<id>`) and the company (`company_id`). Check that it is the company that wrote in, and say nothing about any other company. Then read the lines that follow:

   | What the lines say | What to tell the customer |
   | --- | --- |
   | `http.request` with a 4xx | The request was refused, and why: the problem's `errors`, which the customer has. A 401 or 403 points to the key or the session (an `auth.refused` line names no key) |
   | `http.request` with a 5xx and an `error` | Our failure: check whether an alarm is on ([api-errors.md](api-errors.md)). The customer may retry the request with the same Idempotency-Key, and it takes effect once |
   | `document.issued`, then `dian.call` with `failure`, then `queue.dead_letter` | The DIAN did not respond, and the transmission gave up. It starts again when the dead letters are moved back ([dian-errors.md](dian-errors.md)). The customer may press Retry now |
   | `dian.response` `rejected` and `dian.rule` | The DIAN rejected the document under the rule named. The invoice data must change; then Resend |
   | `delivery.email` `failure` | The email to their customer failed: their customer's mailbox or SES refused it. Use Send again with another address |
   | `webhook.delivery` `failure` | Their endpoint did not accept the webhook ([webhook-failures.md](webhook-failures.md)) |
   | No line at all | The id is from another stage, or older than the log retention (365 days in prod), or the request never reached the API (the firewall, the edge). Ask for the time and the route |

4. **One document in detail**, by the `document_id` on a line: its chain, its timeline and its calls to the DIAN.
   - Emulator: `make cloud-artisan ARGS="chain:status --document=<document id>"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status --document=<document id>"' /dev/stdout`
5. **Reply to the customer** with what happened, what they can do and what the platform does. Leave out other companies' internal ids, and any data about their own customers that they did not send. When it was our failure, say so.

## Afterwards

- A failure on our side that no alarm caught: work out which alarm would have caught it.
- A question that keeps coming back: add a sentence to the API documentation.
