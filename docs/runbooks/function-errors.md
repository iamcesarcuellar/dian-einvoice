# Functions that fail: `function-errors`

| | |
| --- | --- |
| Alarm | `function-errors`: one or more failed invocations of any function in 5 minutes (metric math over the `Errors` of every function) |
| Objective | API availability |
| In the emulator | metric math and the native metrics of Lambda are not evaluated (docs/limitations.md): the errors show in the logs, which `make cloud-errors` reads |

## What it means and who it affects

An invocation of a function failed as a whole: a crash, a timeout, an error at the boot of an execution environment, a command of the relay, the scheduler or the console that exited with an error. Partial failures of a batch (one message of ten) and answers of the API with a 4xx or 5xx are not invocations that failed and do not count here. Who it affects depends on the function:

| Function | When it fails |
| --- | --- |
| `api` | The requests of that environment answer 502 or 500 through the edge: the web app and the integrations see errors ([api-errors.md](api-errors.md)) |
| `chain`, `webhooks` | The whole batch comes back after the visibility timeout (360 s in prod) and is retried; after 10 receives the messages go to the dead-letter queue |
| `relay`, `scheduler` | That minute does nothing; the next one is its retry ([outbox-relay.md](outbox-relay.md)) |
| `console` | The operation that ran fails, with its output |
| `origin-verifier` | API Gateway answers 500 to the requests whose value it could not check ([origin-verifier.md](origin-verifier.md)) |
| `notifier` | Lambda retries it twice, then its dead-letter queue ([notifier.md](notifier.md)) |

## Confirm it

1. **Which function, and what it wrote.**
   - Emulator: `make cloud-errors MINUTES=30` (or `FN=<function>`)
   - AWS: the saved query `dian-einvoice/prod/errors-by-function` over the last 30 minutes, then `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-<function> --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.level = "error" || $.level = "critical" }'`
2. **The invocations that failed, by function**, and whether the failures began with a deployment.
   - Emulator: `make cloud-deploy STEP=versions` (when each function last changed)
   - AWS: `aws cloudwatch get-metric-statistics --namespace AWS/Lambda --metric-name Errors --dimensions Name=FunctionName,Value=dian-einvoice-prod-<function> --start-time <ISO time of an hour ago> --end-time <ISO time now> --period 300 --statistics Sum`, and `sh scripts/deploy.sh prod versions`
3. **Follow one failure** by the correlation id of its line.
   - Emulator: `make cloud-follow ID=<correlation id> HOURS=1`
   - AWS: the saved query `dian-einvoice/prod/follow-a-journey`.

## Probable causes

- **A release**: errors from the minute a deployment ended, the same class in every line.
- **The boot of an environment**: it cannot read its secrets (Secrets Manager unreachable, a permission lost) or reach the database; every new environment fails, warm ones keep serving.
- **The database**: refused connections, too many connections, a failover ([database-capacity.md](database-capacity.md)).
- **A timeout**: a job or a request that waits on a service that does not answer (the DIAN has its own timeout of 8 s; S3, SQS, SNS, KMS, Secrets Manager 10 s each); in prod, the way out through the NAT gateway.
- **Memory**: `Runtime exited` or `out of memory` in the system logs of Lambda; the PDF of a large invoice in the chain.

## Respond

4. **A release: go back to the image before it**, by its digest. The database stays as it is: the migrations only add (make migrations-check), so the earlier code still works on it.
   - Emulator: `make cloud-deploy STEP=rollback-image IMAGE=<an earlier image, from make cloud-deploy STEP=versions>`
   - AWS: `sh scripts/deploy.sh prod rollback-image <an earlier image, from sh scripts/deploy.sh prod versions>`, or the deployment workflow from the commit before.
5. **The boot or the database**: [rotate-secrets.md](rotate-secrets.md) for credentials, [database-capacity.md](database-capacity.md) for connections.
6. **Messages that failed meanwhile** come back by themselves (visibility timeout); those that exhausted their receives are in the dead-letter queue: move them back once the cause is fixed ([dead-letters.md](dead-letters.md)).

## Verify the recovery

7. **No new line of error** in the next minutes, and the API answers through the edge.
   - Emulator: `make cloud-errors MINUTES=10`, then `make cloud-deploy STEP=smoke`
   - AWS: the saved query `dian-einvoice/prod/errors-by-function` over the last 10 minutes, then `sh scripts/deploy.sh prod smoke`
8. **The alarm is back to `OK`** after a period of 5 minutes without errors.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-function-errors --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- A release that failed: the test that would have caught it, before the next one.
- How many requests failed: they count against the 43 minutes a month of the error budget of the API.
