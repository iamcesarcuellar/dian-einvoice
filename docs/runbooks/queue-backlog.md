# A queue that does not move: `chain-queue-age`, `webhooks-queue-age`

| | |
| --- | --- |
| Alarms | `chain-queue-age`, `webhooks-queue-age`: the oldest message of the queue older than 10 minutes, for 5 minutes |
| Objective | Time to the answer of the DIAN (the chain); the webhooks have none of their own |

## What it means and who it affects

A message has waited in `dian-einvoice-prod-chain` or `dian-einvoice-prod-webhooks` longer than any retry of its jobs (the longest wait of the chain is about 144 s, of a webhook 120 s): its consumer function does not take it. For the chain, documents stop between two steps: issued but not transmitted, accepted but not delivered. For the webhooks, the companies stop getting their events. Nothing is lost while the queue keeps the message (4 days); after the receives of the redrive policy a message goes to the dead-letter queue ([dead-letters.md](dead-letters.md)).

## Confirm it

1. **The mapping of the queue and its function**: the mapping must be `Enabled`, the function `Active`.
   - Emulator: `make cloud-functions`
   - AWS: `aws lambda list-event-source-mappings --function-name dian-einvoice-prod-chain --query "EventSourceMappings[].[State,StateTransitionReason,LastProcessingResult]"`, and `aws lambda get-function --function-name dian-einvoice-prod-chain --query "[Configuration.State,Concurrency]"`
2. **The depth and the age of the queue.**
   - Emulator: `make cloud-artisan ARGS="chain:status"` (what the chain holds), and the queue in the web console of the emulator
   - AWS: `aws sqs get-queue-attributes --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-chain --query QueueUrl --output text)" --attribute-names ApproximateNumberOfMessages ApproximateNumberOfMessagesNotVisible ApproximateNumberOfMessagesDelayed`
3. **The errors and the throttles of the consumer**: a consumer that crashes on every batch gives everything back.
   - Emulator: `make cloud-errors FN=chain MINUTES=30`
   - AWS: the saved query `dian-einvoice/prod/errors-by-function`, and the widget "Errors and throttles of the functions" of the dashboard.

## Probable causes

- **The mapping is disabled** (`Disabled`, `Disabling`), by hand or because Lambda disabled it (its function or role deleted, a permission lost).
- **The consumer fails every batch**: an error before any job runs (the boot of the application, its secrets, the database): [function-errors.md](function-errors.md).
- **The consumer is throttled**: its reserved concurrency (chain 10, webhooks 5) or the quota of the account: [function-throttles.md](function-throttles.md).
- **Jobs that take too long**: a step close to its timeout (35 s) is handed back before the deadline of the batch, again and again; the widget "Time of the jobs of the chain" says which.
- **Not a fault**: a retry scheduled far ahead waits invisible, as it should; it does not age the oldest visible message.

## Respond

4. **Put the mapping back as its template declares it**, by deploying the functions with the image they run.
   - Emulator: `make cloud-deploy STEP=functions`
   - AWS: `sh scripts/deploy.sh prod functions <the image the functions run, from sh scripts/deploy.sh prod versions>`
5. **A consumer that fails or is throttled**: its runbook first ([function-errors.md](function-errors.md), [function-throttles.md](function-throttles.md)); when it follows a deployment, go back to the image before it.
   - Emulator: `make cloud-deploy STEP=rollback-image IMAGE=<an earlier image, from make cloud-deploy STEP=versions>`
   - AWS: `sh scripts/deploy.sh prod rollback-image <an earlier image>`

## Verify the recovery

6. **The queue drains**: its oldest message younger than a minute, the documents moving.
   - Emulator: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws sqs get-queue-attributes --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-chain --query QueueUrl --output text)" --attribute-names ApproximateNumberOfMessages`
7. **The alarm is back to `OK`** after 5 minutes under the threshold.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-chain-queue-age --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- The documents that waited more than 5 minutes count against the objective.
- A mapping disabled by hand is drift; a mapping disabled by Lambda names its reason in `StateTransitionReason`: fix that reason in code.
