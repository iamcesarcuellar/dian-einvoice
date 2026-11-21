# Runbooks

What to do when an alarm goes off, and the procedures an operator runs without one. Each alarm names its runbook in its description, so its notification in the chat carries it (docs/observability.md); each runbook says what the alarm means and who it affects, how to confirm it, its probable causes, the response step by step, how to verify the recovery and what to review afterwards.

Every step can be run in both places:

- **Emulator**: a make target against the emulated cloud (`make cloud-up` first). The operation commands of the application run in the console function through `make cloud-artisan ARGS="..."`.
- **AWS**: the command for a real account, with the AWS CLI and the credentials of an operator of the account: a command of the AWS CLI, a saved query of CloudWatch Logs Insights (`dian-einvoice/prod/...`), or a command of the console function, `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"<command>"' /dev/stdout`, whose answer carries what the command printed in `output`. `<account>` stands for the 12 digits of the account.

The tests hold these pages to the code: every alarm names a runbook that exists, every runbook is listed here, and every make target, step of `scripts/deploy.sh`, command of the application and command of the AWS CLI they cite exists (`infra/cdk/test/runbooks.test.ts`, `infra/functions/test/runbooks.test.mjs`, `backend/tests/Integration/Operations/RunbooksTest.php`). Nothing here has been run against a real account; the emulator runs the make targets, and `make cloud-drill` runs the response of `dead-letters.md` end to end.

## Alarms

| Alarm | Runbook | Objective of service it defends |
| --- | --- | --- |
| `chain-dead-letters` | [dead-letters.md](dead-letters.md) | Time to the answer of the DIAN |
| `webhooks-dead-letters` | [dead-letters.md](dead-letters.md) | none: webhooks are delivered at least once, without an objective of their own |
| `chain-queue-age` | [queue-backlog.md](queue-backlog.md) | Time to the answer of the DIAN |
| `webhooks-queue-age` | [queue-backlog.md](queue-backlog.md) | none: webhooks are delivered at least once, without an objective of their own |
| `function-errors` | [function-errors.md](function-errors.md) | API availability |
| `function-throttles` | [function-throttles.md](function-throttles.md) | API availability, API latency |
| `api-server-errors` | [api-errors.md](api-errors.md) | API availability |
| `api-latency` | [api-latency.md](api-latency.md) | API latency |
| `relay-not-running` | [outbox-relay.md](outbox-relay.md) | Time to the answer of the DIAN |
| `outbox-lag` | [outbox-relay.md](outbox-relay.md) | Time to the answer of the DIAN |
| `dian-error-rate` | [dian-errors.md](dian-errors.md) | Time to the answer of the DIAN |
| `webhook-failures` | [webhook-failures.md](webhook-failures.md) | none: webhooks are delivered at least once, without an objective of their own |
| `origin-verifier-errors` | [origin-verifier.md](origin-verifier.md) | API availability |
| `notifier-failures` | [notifier.md](notifier.md) | none directly: it keeps every other alarm reaching the operators |
| `database-capacity` (prod) | [database-capacity.md](database-capacity.md) | API latency |
| `database-connections` (prod) | [database-capacity.md](database-capacity.md) | API availability |
| `service-degraded` (prod) | [service-degraded.md](service-degraded.md) | API availability, API latency, Time to the answer of the DIAN |

The objectives of service, over 30 days (docs/observability.md): **API availability**, 99.9% of the requests to the API answered without a 5xx; **API latency**, 99% answered within 1 second at API Gateway; **Time to the answer of the DIAN**, 99% of the documents issued accepted or rejected by the DIAN within 5 minutes of their issue, the minutes the DIAN itself is down aside.

## Procedures

| Procedure | Runbook |
| --- | --- |
| Rotate a secret out of schedule: the secret of the edge, the address of the chat webhook, the credentials of the database | [rotate-secrets.md](rotate-secrets.md) |
| Attend a customer who reports an error, with the correlation id of their answer | [customer-report.md](customer-report.md) |
| Move a dead-letter queue back with the native redrive of SQS | [dead-letters.md](dead-letters.md), step 5 |
| Deploy, roll back, tear down | [docs/deployment.md](../deployment.md) |

## Drill

[drill-report.md](drill-report.md): the report of one run of `make cloud-drill`, the drill of a DIAN that goes down, as a blameless review of the incident: what happened, its timeline, its impact, what worked and what to improve.

## Before any runbook

1. **Look at every alarm at once.** Emulator: `make cloud-alarms`. AWS: `aws cloudwatch describe-alarms --alarm-name-prefix dian-einvoice-prod- --state-value ALARM --query "MetricAlarms[].[AlarmName,StateReason]" --output table`, and `aws cloudwatch describe-alarms --alarm-types CompositeAlarm --alarm-name-prefix dian-einvoice-prod-`.
2. **Open the dashboard** `dian-einvoice-prod-operations` in CloudWatch (the emulator stores it without drawing it): the API, the edge, the chain, the queues, the DIAN, the outbox and the functions against their limits.
3. **Say it in the chat** where the alarm arrived: who attends it, and when the next update comes. A degraded service is told to the companies on the status channel the operators keep; this project keeps none (docs/limitations.md).
