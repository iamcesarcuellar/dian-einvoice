# The DIAN fails: `dian-error-rate`

| | |
| --- | --- |
| Alarm | `dian-error-rate`: more than 20% of 5 or more calls to the DIAN in 5 minutes without a validation result, for 10 minutes (metric math over `DianCalls` and `DianErrors`) |
| Objective | Time to the answer of the DIAN (the minutes the DIAN itself is down do not count against it) |
| In the emulator | metric math is not evaluated (docs/limitations.md): the outage shows as the dead letters of the chain once the transmissions give up, which `make cloud-drill` rehearses |

## What it means and who it affects

The web service of the DIAN, or the way out of the platform to it (the NAT gateway in prod), does not answer: timeouts, refused connections, HTTP 5xx, answers that are not an answer of the service. Issuing still works: the API numbers and signs every invoice and answers at once, since the transmission is a later step of the chain. What happens next:

| | |
| --- | --- |
| What waits | Each transmission in `sending`, with its next attempt scheduled; nothing is lost, every pending step is an outbox event or a message of the queue `chain` |
| What retries | A first attempt and five retries, 2, 5, 15, 45 and 120 s apart plus jitter (`DIAN_RETRY_DELAYS`; the stage local retries twice, 2 and 5 s); a call that may have arrived asks GetStatus before sending again, and a repeated number is answered by rule 90, so nothing is validated twice |
| What gives up | After the last retry, about three minutes, the document is failed at `transmit` and a copy of its message goes to the dead-letter queue of the chain, where `chain-dead-letters` goes off ([dead-letters.md](dead-letters.md)) |
| What the customer sees | The invoice issued, with its number and CUFE, in `sending` and the time of its next attempt; after the last one, `failed` at `transmit` with the reason (no answer of the DIAN after 6 attempts), and the webhook of the failure. Not validated by the DIAN, the invoice is not delivered to its customer: the email waits for the validation. The company may press Retry now (`POST /api/v1/invoices/{id}/retry`) at any time; once the DIAN answers, the operators retry every one at once (step 5) |

## Confirm it

1. **Is the DIAN up now?** A GetStatus of a document that cannot exist, which changes nothing: it exits 1 when the DIAN does not answer.
   - Emulator: `make cloud-artisan ARGS="dian:probe"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"dian:probe"' /dev/stdout`. Also the status channels of the DIAN itself.
2. **How the calls of the last minutes ended**, and what waits for a retry or gave up.
   - Emulator: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status --minutes=30"' /dev/stdout`, and the widget "The DIAN: calls, errors and latency" of the dashboard.
3. **Follow one transmission**, by the correlation id of the answer that issued its invoice, or of a line that names its document.
   - Emulator: `make cloud-follow ID=<correlation id> HOURS=1`
   - AWS: the saved query `dian-einvoice/prod/follow-a-journey`: the lines `dian.call` with their result and HTTP status.

## Probable causes

- **The DIAN is down or degraded**: every company fails at once, every operation; the probe fails. Nothing to fix on our side but to wait and to tell.
- **The way out of the platform** (prod: the NAT gateway or its zone): the webhooks and SES fail too (`webhook-failures`, emails failing), and the DIAN answers from elsewhere. See the table of failures of docs/infrastructure.md: it recovers by itself when the way out returns; a second NAT gateway (`natGateways: 2`) removes it for the next time.
- **The certificate or the endpoint** (prod: TLS to the DIAN, a changed URL `DIAN_SOAP_URL`, the allow list of the DIAN without the fixed addresses of `/dian-einvoice/prod/network/egress-addresses`): calls fail from the first deployment on, with `client_error` or `refused`.
- **A rule of the DIAN rejects** documents: that is not this alarm (a rejection is an answer); see `DocumentsRejected` and the saved query `dian-einvoice/prod/dian-rejections-by-rule`.

## Respond

4. **Tell the companies** the DIAN is down and their invoices will be validated when it returns, nothing to do on their side. The minutes the DIAN is down do not count against the objective.
5. **When the probe answers again**, move the transmissions that gave up back to the chain: each starts a new run of its transmission ([dead-letters.md](dead-letters.md), step 5).
   - Emulator: `make cloud-artisan ARGS="dian:probe"`, then `make cloud-redrive QUEUE=chain`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"dian:probe"' /dev/stdout`, then `aws sqs start-message-move-task --source-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain-dlq --destination-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain`
6. **The way out** (prod): the NAT gateway and its zone in the console of VPC, and the widget "The way out" of the dashboard (port allocation errors, bytes out).

## Verify the recovery

7. **The calls answer and nothing waits**: no `server_error` in the last minutes, nothing failed at `transmit` since the redrive.
   - Emulator: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status --minutes=10"' /dev/stdout`
8. **The alarms are back to `OK`**: `dian-error-rate` after two periods of 5 minutes with answers, `chain-dead-letters` once its queue is empty.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-dian-error-rate dian-einvoice-prod-chain-dead-letters --query "MetricAlarms[].[AlarmName,StateValue]"`

## Afterwards

- How long the DIAN was down, and how many documents of how many companies waited: the objective counts the documents not answered within 5 minutes, the minutes of the outage aside.
- Whether the five retries fit: they last about three minutes; outages longer than that land in the dead-letter queue and need step 5 each time.
- The DIAN has a procedure of contingency for long outages (invoices of contingency, issued without its validation and sent later), which this platform does not build (docs/limitations.md).
