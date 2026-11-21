# Dead letters: `chain-dead-letters`, `webhooks-dead-letters`

| | |
| --- | --- |
| Alarms | `chain-dead-letters`, `webhooks-dead-letters`: a message in the dead-letter queue of the queue, for 1 minute |
| Objective | Time to the answer of the DIAN (the chain); the webhooks have none of their own |
| Drill | `make cloud-drill` runs this runbook end to end ([drill-report.md](drill-report.md)) |

## What it means and who it affects

Work that will not happen by itself. A message reaches the dead-letter queue `dian-einvoice-prod-chain-dlq` or `dian-einvoice-prod-webhooks-dlq` in three ways:

- **A step gave up for good.** A step of the electronic chain or of the delivery that exhausted its attempts (a transmission the DIAN never answered after a first attempt and five retries, about three minutes; an email the mail server kept refusing) ends its document as failed and leaves a copy of its message in the dead-letter queue, with the reason in the attribute `dead_letter_reason` (log event `queue.dead_letter`).
- **A job kept throwing.** A job that failed with an unexpected error on every one of its 10 receives (the database, the storage, a bug): SQS moves the message by the redrive policy of the queue, and the document is failed at its step.
- **SNS could not deliver an event** to the queue, or a message is unreadable (no handler for its type).

Who it affects: the companies whose documents those messages carry. For the chain, the customer of each document sees it **failed** at its step, with the reason, in the web app and through the API (`electronic_status` `failed`, `electronic_failed_step` `transmit`), and gets the webhook of the failure; the DIAN has not validated it, so it was not delivered. For the webhooks, a company misses an event at its endpoint. Nothing is lost: the dead-letter queue keeps every message 14 days, and moving one back retries its step.

## Confirm it

1. **See the messages without consuming them**: when each came, how many times it was received, its type, company, document and journey, and why its job gave up.
   - Emulator: `make cloud-dead-letters QUEUE=chain` (or `QUEUE=webhooks`)
   - AWS: `aws sqs receive-message --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-chain-dlq --query QueueUrl --output text)" --visibility-timeout 0 --max-number-of-messages 10 --attribute-names All --message-attribute-names All`. With a visibility of 0 nothing is consumed; repeat it to see more than 10. Read the attributes, not the body, which carries the event.
2. **Follow the journey of one of them** with its correlation id (the attribute `correlation_id`): every line of its request, its jobs and the reason its step gave up.
   - Emulator: `make cloud-follow ID=<correlation id> HOURS=1`
   - AWS: the saved query `dian-einvoice/prod/follow-a-journey` in CloudWatch Logs Insights, with the id in place of `REPLACE-WITH-THE-CORRELATION-ID`, over the hours since the message came.
3. **See the documents the chain left failed**, by step and reason, and the last calls to the DIAN.
   - Emulator: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status"' /dev/stdout`

## Probable causes

| What the reasons say | Cause | Go to |
| --- | --- | --- |
| `Attempt 6 of 6` of a transmission, `server_error`, `timeout`, `refused` | The DIAN, or the way out to it, was down longer than the retries of a transmission | [dian-errors.md](dian-errors.md), then step 5 here |
| An email `Failed` at its last attempt | The mail service (SES) or the way out to it | [function-errors.md](function-errors.md), then step 5 |
| `Unexpected error` and an exception, received 10 or more times | The database, the storage, KMS, or a bug of a release | [function-errors.md](function-errors.md), [database-capacity.md](database-capacity.md); a release: step 4 of [api-errors.md](api-errors.md) |
| A message without `dead_letter_reason`, received once or never | SNS could not deliver an event to the queue (the policy of the queue, KMS) | the policy of the queue, in the stack messaging; then step 5 |
| `jobs of the chain queue take events of type ...` | An event type no job subscribes to: a release that routes an event the consumer does not know | the routes of `backend/resources/outbox/routes.json` and the subscribers; then step 5 |

## Respond

4. **Fix the cause first**, with the runbook the table names. Moving messages back while the cause lasts sends them back here: a transmission would give up again after its retries.
5. **Move the messages back to their queue**, with the native redrive of SQS (a message move task). Each step starts again where it gave up: a document failed at a step in that run starts a new run from that step, as the action Retry now of the web app does; a message of a document a user already retried, or one that comes back twice, does nothing (docs/infrastructure.md, the dead letters).
   - Emulator: `make cloud-redrive QUEUE=chain` (or `QUEUE=webhooks`)
   - AWS: `aws sqs start-message-move-task --source-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain-dlq --destination-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain`, then `aws sqs list-message-move-tasks --source-arn arn:aws:sqs:us-east-1:<account>:dian-einvoice-prod-chain-dlq` until its status is `COMPLETED`. The destination is always the queue of the channel: it moves both the messages the redrive policy moved and those a job copied there when it gave up. To move them slowly, add `--max-number-of-messages-per-second 10`.

   **Retry now and the redrive, in either order.** A failed invoice has two ways back: its company presses Retry now (the web app, or `POST /api/v1/invoices/{id}/retry` through the API), and the operator moves its dead letter back. Whichever comes first recovers it and the other does nothing, so the dead letters go back without asking the companies first, and a company may press Retry now at any time:
   - Retry now first: the invoice starts a new run; its dead letter, moved back afterwards, belongs to a run already retried and does nothing.
   - The redrive first: the step starts a new run; a Retry now afterwards is refused (409) once the invoice is accepted, and while the new run waits for a retry it only brings that retry forward, as it always does.
   - Both at the same moment: each checks, under the lock of the invoice, that it is still failed in that run, so only one of them starts a run.

   Either way the invoice goes to the DIAN once more, its customer gets one email, each subscription one webhook of each event, and nothing else of the invoice changes (`backend/tests/Integration/Dian/RecoveryTest.php`). An email that gave up behaves the same between Send again and its dead letter, with one difference: Send again on an invoice already delivered sends it again on purpose, so tell a company whose invoice the redrive delivered that it was. A dead letter keeps the journey of its invoice: moved back, its step goes on under the same correlation id, and step 2 then tells the failure, the dead letter, the step that came back and the recovery as one story. A Retry now is a journey of its own, the request of the company; the timeline of the invoice in the web app shows both.
6. **A message that cannot run again** (a bug that a release has not fixed yet, an event of a type nobody handles): leave it in the dead-letter queue, which keeps it 14 days, until the release that fixes it is deployed; the alarm stays in `ALARM` meanwhile, so say so in the chat.

## Verify the recovery

7. **The dead-letter queue is empty**, and the queue drains.
   - Emulator: `make cloud-dead-letters QUEUE=chain`
   - AWS: `aws sqs get-queue-attributes --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-chain-dlq --query QueueUrl --output text)" --attribute-names ApproximateNumberOfMessages ApproximateNumberOfMessagesNotVisible`
8. **The documents went through**: none left failed by the incident, and the calls to the DIAN answer.
   - Emulator: `make cloud-artisan ARGS="chain:status"`; one document: `make cloud-artisan ARGS="chain:status --document=<id>"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status"' /dev/stdout`
9. **The alarm goes back to `OK` by itself** within a few minutes, and the chat says so.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-chain-dead-letters --query "MetricAlarms[].[StateValue,StateUpdatedTimestamp]"`

## Afterwards

- How many documents, of how many companies, and for how long: the companies whose invoices waited may need a word, since their customers waited for them.
- Whether the retries of the chain fit the outage: a DIAN often down for longer than three minutes is a reason to retry for longer before giving up (`DIAN_RETRY_DELAYS`, a parameter of the stage of the functions).
- A cause that was a release: what test would have caught it.
- Write the review, without blame, as [drill-report.md](drill-report.md) does.
