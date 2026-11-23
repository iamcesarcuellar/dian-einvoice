# Functions that fail: `function-errors`

| | |
| --- | --- |
| Alarm | `function-errors`: one or more failed invocations of any function in 5 minutes (metric math over every function's `Errors`) |
| Service objective | API availability |
| In the emulator | The emulator evaluates neither metric math nor Lambda's native metrics (docs/limitations.md). The errors show in the logs, which `make cloud-errors` reads. |

## What it means and who it affects

A function invocation failed as a whole:

- a crash or a timeout;
- an error while an execution environment boots;
- a relay, scheduler or console command that exited with an error.

Partial batch failures (one message in ten) and API responses with a 4xx or 5xx are not failed invocations, so they do not count here. Who it affects depends on the function:

| Function | When it fails |
| --- | --- |
| `api` | Requests to that environment get a 502 or 500 through the edge, so the web app and the integrations see errors ([api-errors.md](api-errors.md)). |
| `chain`, `webhooks` | The whole batch returns to the queue after the visibility timeout (360 s in prod) and is retried. After 10 receives, its messages go to the dead-letter queue. |
| `relay`, `scheduler` | That minute's run does nothing, and the next minute's run is its retry ([outbox-relay.md](outbox-relay.md)). |
| `console` | The operation it ran fails, with its output. |
| `origin-verifier` | API Gateway returns 500 for the requests whose `X-Origin-Verify` value the verifier could not check ([origin-verifier.md](origin-verifier.md)). |
| `notifier` | Lambda retries it twice, then sends it to its dead-letter queue ([notifier.md](notifier.md)). |

## Confirm it

1. **Which function failed, and what it logged.**
   - Emulator: `make cloud-errors MINUTES=30` (or `FN=<function>`)
   - AWS: the saved query `dian-einvoice/prod/errors-by-function` over the last 30 minutes, then `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-<function> --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.level = "error" || $.level = "critical" }'`
2. **Failed invocations by function**, and whether the failures began with a deployment.
   - Emulator: `make cloud-deploy STEP=versions` (when each function last changed)
   - AWS: `aws cloudwatch get-metric-statistics --namespace AWS/Lambda --metric-name Errors --dimensions Name=FunctionName,Value=dian-einvoice-prod-<function> --start-time <ISO time of an hour ago> --end-time <ISO time now> --period 300 --statistics Sum`, and `sh scripts/deploy.sh prod versions`
3. **Follow one failure** by its log line's correlation id.
   - Emulator: `make cloud-follow ID=<correlation id> HOURS=1`
   - AWS: the saved query `dian-einvoice/prod/follow-a-journey`.

## Probable causes

- **A release**: errors start the minute a deployment ends, with the same error class in every line.
- **Environment boot**: a new environment cannot read its secrets (Secrets Manager is unreachable, or a permission was lost) or cannot reach the database. Every new environment fails, while warm ones keep serving.
- **The database**: refused connections, too many connections, a failover ([database-capacity.md](database-capacity.md)).
- **A timeout**: a job or a request waits on a service that does not respond. The DIAN has its own 8 s timeout, and S3, SQS, SNS, KMS and Secrets Manager have 10 s each. In prod, the cause may be the outbound path through the NAT gateway.
- **Memory**: `Runtime exited` or `out of memory` in Lambda's system logs, for example while the chain draws a large invoice's PDF.

## Respond

4. **A release: roll back to the previous image**, by its digest. The database stays as it is. The migrations only add (make migrations-check), so the earlier code still works with it.
   - Emulator: `make cloud-deploy STEP=rollback-image IMAGE=<an earlier image, from make cloud-deploy STEP=versions>`
   - AWS: `sh scripts/deploy.sh prod rollback-image <an earlier image, from sh scripts/deploy.sh prod versions>`, or the deployment workflow from the previous commit.
5. **Boot or database failures**: see [rotate-secrets.md](rotate-secrets.md) for credentials and [database-capacity.md](database-capacity.md) for connections.
6. **Messages that failed meanwhile** come back by themselves, after the visibility timeout. Those that used up their receives are in the dead-letter queue: move them back once the cause is fixed ([dead-letters.md](dead-letters.md)).

## Verify the recovery

7. **No new error lines** in the next few minutes, and the API responds through the edge.
   - Emulator: `make cloud-errors MINUTES=10`, then `make cloud-deploy STEP=smoke`
   - AWS: the saved query `dian-einvoice/prod/errors-by-function` over the last 10 minutes, then `sh scripts/deploy.sh prod smoke`
8. **The alarm is back to `OK`** after one 5-minute period without errors.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-function-errors --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- A failed release: add the test that would have caught it, before the next release.
- How many requests failed: they count against the API's error budget of 43 minutes a month.
