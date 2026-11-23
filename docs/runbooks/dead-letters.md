# Dead letters: `chain-dead-letters`, `webhooks-dead-letters`

| | |
| --- | --- |
| Alarms | `chain-dead-letters`, `webhooks-dead-letters`: a message in the queue's dead-letter queue for 1 minute |
| Service objective | Time to the DIAN response (the chain); the webhooks have none of their own |
| Drill | `make cloud-drill` runs this runbook end to end ([drill-report.md](drill-report.md)) |

## What it means and who it affects

Work that will not happen by itself. A message reaches the dead-letter queue `dian-einvoice-prod-chain-dlq` or `dian-einvoice-prod-webhooks-dlq` in one of three ways:

- **A step gave up for good.** A chain or delivery step ran out of attempts. Examples: a transmission the DIAN never responded to after a first attempt and five retries (about three minutes), or an email the mail server kept refusing. The step leaves its document failed and puts a copy of its message in the dead-letter queue. The reason is in the `dead_letter_reason` attribute (log event `queue.dead_letter`).
- **A job kept throwing.** A job failed with an unexpected error on every one of its 10 receives (the database, the storage, a bug). SQS moves the message under the queue's redrive policy, and the document is left failed at its step.
- **SNS could not deliver an event** to the queue, or a message is unreadable (no handler for its type).

Who it affects: the companies whose documents those messages carry.

- **The chain**: each document's company sees it **failed** at its step, with the reason, in the web app and through the API (`electronic_status` `failed`, `electronic_failed_step` `transmit`). The company also gets the failure webhook. The DIAN has not validated the document, so it was not delivered.
- **The webhooks**: a company misses an event at its endpoint.

Nothing is lost. The dead-letter queue keeps every message for 14 days, and moving a message back retries its step.

## Confirm it

1. **See the messages without consuming them**: when each one arrived, how many times it was received, its type, company, document and journey, and why its job gave up.
   - Emulator: `make cloud-dead-letters QUEUE=chain` (or `QUEUE=webhooks`)
   - AWS: `aws sqs receive-message --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-chain-dlq --query QueueUrl --output text)" --visibility-timeout 0 --max-number-of-messages 10 --attribute-names All --message-attribute-names All`. With a visibility timeout of 0, nothing is consumed; repeat the command to see more than 10. Read the attributes, not the body, which carries the event.
2. **Follow one message's journey** by its correlation id (the `correlation_id` attribute): every line of its request and its jobs, and the reason its step gave up.
   - Emulator: `make cloud-follow ID=<correlation id> HOURS=1`
   - AWS: the saved query `dian-einvoice/prod/follow-a-journey` in CloudWatch Logs Insights, with the id in place of `REPLACE-WITH-THE-CORRELATION-ID`, over the hours since the message arrived.
3. **See the documents the chain left failed**, by step and reason, and the latest calls to the DIAN.
   - Emulator: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status"' /dev/stdout`

## Probable causes

| What the reasons say | Cause | Go to |
| --- | --- | --- |
| A transmission's `Attempt 6 of 6`, with `server_error`, `timeout` or `refused` | The DIAN, or the outbound path to it, was down for longer than a transmission's retries last | [dian-errors.md](dian-errors.md), then step 5 here |
| An email `Failed` at its last attempt | The mail service (SES), or the outbound path to it | [function-errors.md](function-errors.md), then step 5 |
| `Unexpected error` and an exception, received 10 or more times | The database, the storage, KMS, or a bug in a release | [function-errors.md](function-errors.md), [database-capacity.md](database-capacity.md); a release: [api-errors.md](api-errors.md), step 4 |
| A message without `dead_letter_reason`, received once or never | SNS could not deliver an event to the queue (the queue policy, KMS) | the queue policy, in the messaging stack; then step 5 |
| `jobs of the chain queue take events of type ...` | An event type no job subscribes to: a release routes an event the consumer does not know | the routes in `backend/resources/outbox/routes.json`, and the subscribers; then step 5 |

## Respond

4. **Fix the cause first**, with the runbook the table names. Messages moved back while the cause lasts come straight back here: a transmission would give up again after its retries.
5. **Move the messages back to their queue** with the native SQS redrive (a message move task). Each step starts again where it gave up. A document that failed at a step in that run starts a new run from that step, as the web app's Retry now action does. A message for a document a user already retried, or a message that comes back twice, does nothing (see the dead letters section of docs/infrastructure.md).
   - Emulator: `make cloud-redrive QUEUE=chain` (or `QUEUE=webhooks`)
   - AWS: `aws sqs start-message-move-task --source-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain-dlq --destination-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain`, then `aws sqs list-message-move-tasks --source-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain-dlq` until its status is `COMPLETED`. The destination is always the channel's queue, so the task moves both kinds of message: those the redrive policy moved, and those a job copied there when it gave up. To move them slowly, add `--max-number-of-messages-per-second 10`.

   **Retry now and the redrive, in either order.** A failed invoice has two ways back. Its company can press Retry now (in the web app, or `POST /api/v1/invoices/{id}/retry` through the API), and the operator can move its dead letter back. Whichever comes first recovers the invoice, and the other does nothing. So operators move dead letters back without asking the companies first, and a company may press Retry now at any time:
   - **Retry now first**: the invoice starts a new run. Its dead letter, moved back afterwards, belongs to a run already retried and does nothing.
   - **The redrive first**: the step starts a new run. A later Retry now is refused (409) once the invoice is accepted. While the new run waits for a retry, Retry now only brings that retry forward, as it always does.
   - **Both at the same moment**: each checks, under the invoice's lock, that the invoice is still failed in that run, so only one of them starts a run.

   Either way, the invoice goes to the DIAN once more, its customer gets one email, and each subscription gets one webhook per event. Nothing else about the invoice changes (`backend/tests/Integration/Dian/RecoveryTest.php`).

   An email that gave up behaves the same way between Send again and its dead letter, with one difference. Send again on an invoice that was already delivered sends it again on purpose. So when the redrive delivers an invoice, tell its company that it was delivered.

   A dead letter keeps its invoice's journey. Once moved back, its step goes on under the same correlation id. Step 2 then tells the failure, the dead letter, the resumed step and the recovery as one story. A Retry now is a journey of its own: the company's request. The web app's invoice timeline shows both.
6. **A message that cannot run again**: a bug no release has fixed yet, or an event of a type nobody handles. Leave it in the dead-letter queue, which keeps it for 14 days, until the release that fixes it is deployed. The alarm stays in `ALARM` meanwhile, so say so in the chat.

## Verify the recovery

7. **The dead-letter queue is empty**, and the queue drains.
   - Emulator: `make cloud-dead-letters QUEUE=chain`
   - AWS: `aws sqs get-queue-attributes --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-chain-dlq --query QueueUrl --output text)" --attribute-names ApproximateNumberOfMessages ApproximateNumberOfMessagesNotVisible`
8. **The documents went through**: the incident left none failed, and the calls to the DIAN get responses.
   - Emulator: `make cloud-artisan ARGS="chain:status"`; one document: `make cloud-artisan ARGS="chain:status --document=<id>"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status"' /dev/stdout`
9. **The alarm goes back to `OK` by itself** within a few minutes, and the chat says so.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-chain-dead-letters --query "MetricAlarms[].[StateValue,StateUpdatedTimestamp]"`

## Afterwards

- How many documents waited, from how many companies, and for how long. The companies whose invoices waited may need a word, since their customers waited for those invoices.
- Whether the chain's retries fit the outage. If the DIAN is often down for longer than three minutes, retry for longer before giving up (`DIAN_RETRY_DELAYS`, a stage parameter of the functions).
- If a release was the cause: which test would have caught it.
- Write a blameless review, as [drill-report.md](drill-report.md) does.
