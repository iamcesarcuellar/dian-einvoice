# A queue that stops moving: `chain-queue-age`, `webhooks-queue-age`

**English** · [Español](../es/runbooks/queue-backlog.md)

| | |
| --- | --- |
| Alarms | `chain-queue-age`, `webhooks-queue-age`: the queue's oldest message is more than 10 minutes old, for 5 minutes |
| Service objective | Time to the DIAN response (the chain); the webhooks have none of their own |

## What it means and who it affects

A message has waited in `dian-einvoice-prod-chain` or `dian-einvoice-prod-webhooks` for longer than any retry of its jobs: its consumer function does not take it. The longest wait between retries is about 144 s, in the chain and for a webhook alike.

- In the chain, documents stop between two steps: issued but not transmitted, or accepted but not delivered.
- In the webhooks queue, the companies stop getting their events.

Nothing is lost while the queue keeps the message (4 days). Once a message uses up the receives its redrive policy allows, it goes to the dead-letter queue ([dead-letters.md](dead-letters.md)).

## Confirm it

The AWS commands below name the chain's resources. For `webhooks-queue-age`, use `dian-einvoice-prod-webhooks` for the function and the queue, and `dian-einvoice-prod-webhooks-queue-age` for the alarm.

1. **The queue's mapping and its function**: the mapping must be `Enabled`, and the function `Active`.
   - Emulator: `make cloud-functions`
   - AWS: `aws lambda list-event-source-mappings --function-name dian-einvoice-prod-chain --query "EventSourceMappings[].[State,StateTransitionReason,LastProcessingResult]"`, and `aws lambda get-function --function-name dian-einvoice-prod-chain --query "[Configuration.State,Concurrency]"`
2. **The queue's depth and age.**
   - Emulator: `make cloud-artisan ARGS="chain:status"` (what the chain holds), and the queue in the emulator's web console
   - AWS: `aws sqs get-queue-attributes --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-chain --query QueueUrl --output text)" --attribute-names ApproximateNumberOfMessages ApproximateNumberOfMessagesNotVisible ApproximateNumberOfMessagesDelayed`
3. **The consumer's errors and throttles**: a consumer that crashes on every batch hands everything back.
   - Emulator: `make cloud-errors FN=chain MINUTES=30`
   - AWS: the saved query `dian-einvoice/prod/errors-by-function`, and the dashboard's "Function errors and throttles" widget.

## Probable causes

- **The mapping is disabled** (`Disabled`, `Disabling`), by hand or by Lambda: its function or role was deleted, or a permission was lost.
- **The consumer fails every batch**: an error before any job runs (the application's boot, its secrets, the database): [function-errors.md](function-errors.md).
- **The consumer is throttled**: its reserved concurrency (chain 10, webhooks 5) or the account quota: [function-throttles.md](function-throttles.md).
- **Jobs that take too long**: a step close to its timeout (35 s) is handed back before the batch's deadline, again and again. The dashboard's "Chain job duration, p95 (ms)" widget shows which step.
- **Not a fault**: a retry scheduled far ahead waits invisible, as it should. It does not raise the age of the oldest visible message.

## Respond

4. **Restore the mapping as its template declares it**, by deploying the functions with the image they run.
   - Emulator: `make cloud-deploy STEP=functions`
   - AWS: `sh scripts/deploy.sh prod functions <the image the functions run, from sh scripts/deploy.sh prod versions>`
5. **A consumer that fails or is throttled**: follow its runbook first ([function-errors.md](function-errors.md), [function-throttles.md](function-throttles.md)). When the problem follows a deployment, roll back to the previous image.
   - Emulator: `make cloud-deploy STEP=rollback-image IMAGE=<an earlier image, from make cloud-deploy STEP=versions>`
   - AWS: `sh scripts/deploy.sh prod rollback-image <an earlier image>`

## Verify the recovery

6. **The queue drains**: its oldest message is less than a minute old, and the documents are moving.
   - Emulator: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws sqs get-queue-attributes --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-chain --query QueueUrl --output text)" --attribute-names ApproximateNumberOfMessages`
7. **The alarm is back to `OK`** after 5 minutes below the threshold.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-chain-queue-age --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- Documents that waited more than 5 minutes count against the service objective.
- A mapping disabled by hand is drift. When Lambda disables a mapping, it gives the reason in `StateTransitionReason`: fix that reason in code.
