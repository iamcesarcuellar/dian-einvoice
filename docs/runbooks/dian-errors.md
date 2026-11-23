# The DIAN fails: `dian-error-rate`

| | |
| --- | --- |
| Alarm | `dian-error-rate`: more than 20% of the calls to the DIAN in 5 minutes get no validation result, with 5 calls or more, for 10 minutes. It uses metric math over `DianCalls` and `DianErrors` |
| Service objective | Time to the DIAN response (the minutes the DIAN itself is down do not count against it) |
| In the emulator | Metric math is not evaluated (docs/limitations.md). The outage shows up as the chain's dead letters once the transmissions give up, which `make cloud-drill` rehearses |

## What it means and who it affects

The DIAN web service, or the platform's outbound path to it (the NAT gateway in prod), is not responding. The calls end in timeouts, refused connections, HTTP 5xx, or responses that do not come from the service. Issuing still works. The API numbers every invoice, gives it its CUFE and responds at once, because signing and transmitting are later chain steps. What happens next:

| | |
| --- | --- |
| What waits | Each transmission in `sending`, with its next attempt scheduled. Nothing is lost: every pending step is an outbox event or a message in the `chain` queue |
| What retries | A first attempt and five retries, 2, 5, 15, 45 and 120 s apart, plus jitter (`DIAN_RETRY_DELAYS`; the local stage retries twice, after 2 and 5 s). When a call may have arrived, the transmission asks GetStatus before sending again. The DIAN responds to a repeated number with rule 90, so nothing is validated twice |
| What gives up | After the last retry (about three minutes), the document is failed at `transmit`. A copy of its message goes to the chain's dead-letter queue, where `chain-dead-letters` goes off ([dead-letters.md](dead-letters.md)) |
| What the company sees | The invoice issued, with its number and CUFE, in `sending` with the time of its next attempt. After the last attempt: `failed` at `transmit` with the reason (no DIAN response after 6 attempts), and the failure webhook |

The DIAN has not validated the invoice, so it is not delivered to its customer: the email waits for the validation. The company may press Retry now (`POST /api/v1/invoices/{id}/retry`) at any time. Once the DIAN responds again, the operators retry every invoice at once (step 5).

## Confirm it

1. **Is the DIAN up now?** The probe asks GetStatus for a document that cannot exist, which changes nothing. It exits 1 when the DIAN does not respond.
   - Emulator: `make cloud-artisan ARGS="dian:probe"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"dian:probe"' /dev/stdout`. Also check the DIAN's own status channels.
2. **How the calls in the last few minutes ended**, and what is waiting for a retry or has given up.
   - Emulator: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status --minutes=30"' /dev/stdout`, and the dashboard's "DIAN calls, errors and latency" widget.
3. **Follow one transmission** by a correlation id: the one in the response that issued its invoice, or the one on a log line that names its document.
   - Emulator: `make cloud-follow ID=<correlation id> HOURS=1`
   - AWS: the saved query `dian-einvoice/prod/follow-a-journey`: the `dian.call` lines, with their result and HTTP status.

## Probable causes

- **The DIAN is down or degraded**: every company fails at once, in every operation, and the probe fails. There is nothing to fix on our side: wait, and keep the companies informed.
- **The platform's outbound path** (prod: the NAT gateway or its zone): webhooks and SES fail too (`webhook-failures`, failing emails), and the DIAN responds from elsewhere. It recovers by itself when the outbound path returns (see the failure table in docs/infrastructure.md). A second NAT gateway (`natGateways: 2`) removes this cause for next time.
- **The certificate or the endpoint** (prod): TLS to the DIAN, a changed URL in `DIAN_SOAP_URL`, or a DIAN allow list without the fixed addresses in `/dian-einvoice/prod/network/egress-addresses`. Calls fail from the first deployment on, with `client_error` or `refused`.
- **A DIAN rule rejects documents**: that is not this alarm, since a rejection is a response. See `DocumentsRejected` and the saved query `dian-einvoice/prod/dian-rejections-by-rule`.

## Respond

4. **Tell the companies** that the DIAN is down and that their invoices will be validated when it returns; they have nothing to do. The minutes the DIAN is down do not count against the service objective.
5. **When the probe passes again**, move the transmissions that gave up back to the chain. Each one starts a new run of its transmission ([dead-letters.md](dead-letters.md), step 5).
   - Emulator: `make cloud-artisan ARGS="dian:probe"`, then `make cloud-redrive QUEUE=chain`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"dian:probe"' /dev/stdout`, then `aws sqs start-message-move-task --source-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain-dlq --destination-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain`
6. **The outbound path** (prod): check the NAT gateway and its zone in the VPC console, and the dashboard's "Outbound traffic" widget (port allocation errors, bytes out).

## Verify the recovery

7. **The calls get responses and nothing waits**: no `server_error` in the last few minutes, and nothing failed at `transmit` since the redrive.
   - Emulator: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status --minutes=10"' /dev/stdout`
8. **The alarms are back to `OK`**: `dian-error-rate` after two 5-minute periods with responses, and `chain-dead-letters` once its queue is empty.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-dian-error-rate dian-einvoice-prod-chain-dead-letters --query "MetricAlarms[].[AlarmName,StateValue]"`

## Afterwards

- How long the DIAN was down, and how many documents from how many companies waited. The service objective counts the documents without a DIAN response within 5 minutes, not counting the minutes of the outage.
- Whether the five retries fit. They last about three minutes; longer outages land in the dead-letter queue and need step 5 each time.
- The DIAN has a contingency procedure for long outages (contingency invoices, issued without its validation and sent later). This platform does not build it (docs/limitations.md).
