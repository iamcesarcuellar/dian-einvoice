# A customer reports an error

A procedure, without an alarm: a company writes that something failed, an invoice that did not go out, an answer of the API that was an error. Every answer of the API carries `X-Correlation-Id`, and every error in `application/problem+json` also carries it as `correlation_id` next to `request_id`: with it, one query shows every line of that journey, from the request to its webhooks, in every function (docs/observability.md, the correlation id).

1. **Ask for the correlation id**, and only for it: the `X-Correlation-Id` of the answer, or the `correlation_id` of the error, or the time and the route of the request. Never ask for a password, an API key, a certificate or the data of their customers: the id is enough, and it is not a secret. An integration should log it with every answer. The web app does not show it to its user yet (docs/limitations.md): a user of the web app finds it in the developer tools of the browser, in the answer of the request that failed, or gives the time and what they did.
2. **Follow the journey**: every line of that request and of the jobs it started, in order, with the function, the event, the outcome and the message.
   - Emulator: `make cloud-follow ID=<correlation id> HOURS=24`
   - AWS: the saved query `dian-einvoice/prod/follow-a-journey` in CloudWatch Logs Insights, with the id in place of `REPLACE-WITH-THE-CORRELATION-ID`, over the days since the request.
3. **Read what happened.** The first line is the request (`http.request`, its route and status), with its actor (`user:<id>` or `api-key:<id>`) and its company (`company_id`): check it is the company that wrote, and say nothing about any other. Then:

   | What the lines say | What to tell the customer |
   | --- | --- |
   | `http.request` with a 4xx | The request was refused and why: the `errors` of the problem, which the customer has; a 401 or 403, the key or the session (an `auth.refused` line names no key) |
   | `http.request` with a 5xx and an `error` | Our failure: is an alarm on ([api-errors.md](api-errors.md))? The request may be retried with the same Idempotency-Key, which acts once |
   | `document.issued`, then `dian.call` with `failure`, then `queue.dead_letter` | The DIAN did not answer and the transmission gave up; it starts again when the dead letters are moved back ([dian-errors.md](dian-errors.md)); the customer may press Retry now |
   | `dian.response` `rejected` and `dian.rule` | The DIAN rejected the document by the rule named: the data of the invoice must change, then Resend |
   | `delivery.email` `failure` | The email to their customer failed: the mailbox of their customer refused it, or SES; Send again with another address |
   | `webhook.delivery` `failure` | Their endpoint did not take the webhook ([webhook-failures.md](webhook-failures.md)) |
   | No line at all | The id is of another stage, older than the retention of the logs (365 days in prod), or the request never reached the API (the firewall, the edge): ask for the time and the route |

4. **One document in detail**, by the `document_id` of a line: its chain, its timeline and its calls to the DIAN.
   - Emulator: `make cloud-artisan ARGS="chain:status --document=<document id>"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status --document=<document id>"' /dev/stdout`
5. **Answer the customer** with what happened, what they can do, and what the platform does, without internal ids of other companies or anything of their customers they did not send. When it was our failure, say so.

## Afterwards

- A failure on our side that no alarm caught: what alarm would have.
- A question that comes back: a sentence in the documentation of the API.
