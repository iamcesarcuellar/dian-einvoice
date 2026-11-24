# Runbooks

**English** · [Español](../es/runbooks/README.md)

This folder holds what to do when an alarm goes off, and the procedures an operator runs without one. Each alarm names its runbook in its description, so the alarm's chat notification carries it (docs/observability.md). Each runbook covers:

- what the alarm means and who it affects;
- how to confirm it;
- its probable causes;
- the response, step by step;
- how to verify the recovery;
- what to review afterwards.

Every step can run in two places:

- **Emulator**: a make target against the emulated cloud (`make cloud-up` first). The application's operation commands run in the console function through `make cloud-artisan ARGS="..."`.
- **AWS**: the command for a real account, run with the AWS CLI and an operator's credentials for that account. It is one of these:
  - an AWS CLI command;
  - a CloudWatch Logs Insights saved query (`dian-einvoice/prod/...`);
  - a console function command: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"<command>"' /dev/stdout`. Its response carries what the command printed, in `output`.

  `<account>` stands for the 12-digit account ID.

Tests hold these pages to the code (`infra/cdk/test/runbooks.test.ts`, `infra/functions/test/runbooks.test.mjs`, `backend/tests/Integration/Operations/RunbooksTest.php`):

- every alarm names a runbook that exists;
- every runbook is listed here;
- every make target, `scripts/deploy.sh` step, application command and AWS CLI command the pages cite exists.

Nothing here has been run against a real account. The emulator runs the make targets, and `make cloud-drill` runs the `dead-letters.md` response end to end.

## Alarms

| Alarm | Runbook | Service objectives it defends |
| --- | --- | --- |
| `chain-dead-letters` | [dead-letters.md](dead-letters.md) | Time to the DIAN response |
| `webhooks-dead-letters` | [dead-letters.md](dead-letters.md) | none: webhooks are delivered at least once, with no service objective of their own |
| `chain-queue-age` | [queue-backlog.md](queue-backlog.md) | Time to the DIAN response |
| `webhooks-queue-age` | [queue-backlog.md](queue-backlog.md) | none: webhooks are delivered at least once, with no service objective of their own |
| `function-errors` | [function-errors.md](function-errors.md) | API availability |
| `function-throttles` | [function-throttles.md](function-throttles.md) | API availability, API latency |
| `api-server-errors` | [api-errors.md](api-errors.md) | API availability |
| `api-latency` | [api-latency.md](api-latency.md) | API latency |
| `relay-not-running` | [outbox-relay.md](outbox-relay.md) | Time to the DIAN response |
| `outbox-lag` | [outbox-relay.md](outbox-relay.md) | Time to the DIAN response |
| `dian-error-rate` | [dian-errors.md](dian-errors.md) | Time to the DIAN response |
| `webhook-failures` | [webhook-failures.md](webhook-failures.md) | none: webhooks are delivered at least once, with no service objective of their own |
| `origin-verifier-errors` | [origin-verifier.md](origin-verifier.md) | API availability |
| `notifier-failures` | [notifier.md](notifier.md) | none directly: it keeps every other alarm reaching the operators |
| `database-capacity` (prod) | [database-capacity.md](database-capacity.md) | API latency |
| `database-connections` (prod) | [database-capacity.md](database-capacity.md) | API availability |
| `service-degraded` (prod) | [service-degraded.md](service-degraded.md) | API availability, API latency, Time to the DIAN response |

The service objectives (docs/observability.md):

- **API availability**: 99.9% of API requests over 30 days return no 5xx (an error budget of 43 minutes a month).
- **API latency**: 99% of API requests over 30 days get a response within 1 second at API Gateway.
- **Time to the DIAN response**: 99% of the documents issued over 30 days are accepted or rejected by the DIAN within 5 minutes of their issue, not counting the minutes the DIAN itself is down.

## Procedures

| Procedure | Runbook |
| --- | --- |
| Rotate a secret off schedule: the edge secret, the chat webhook address or the database credentials | [rotate-secrets.md](rotate-secrets.md) |
| Handle a customer who reports an error, with the correlation id of the response they got | [customer-report.md](customer-report.md) |
| Move dead letters back to their queue with the native SQS redrive | [dead-letters.md](dead-letters.md), step 5 |
| Deploy, roll back, tear down | [docs/deployment.md](../deployment.md) |

## Drill

[drill-report.md](drill-report.md) reports one run of `make cloud-drill`, the drill in which the DIAN goes down. It is a blameless incident review: what happened, the timeline, the impact, what worked and what to improve.

## Before any runbook

1. **Check every alarm at once.** Emulator: `make cloud-alarms`. AWS: `aws cloudwatch describe-alarms --alarm-name-prefix dian-einvoice-prod- --state-value ALARM --query "MetricAlarms[].[AlarmName,StateReason]" --output table`, and `aws cloudwatch describe-alarms --alarm-types CompositeAlarm --alarm-name-prefix dian-einvoice-prod-` for the composite alarm.
2. **Open the dashboard** `dian-einvoice-prod-operations` in CloudWatch. It shows the API, the edge, the chain, the queues, the DIAN, the outbox and the functions against their limits. The emulator stores the dashboard but does not draw it.
3. **Post in the chat** where the alarm arrived: who is handling it, and when the next update comes. Operators tell the companies about a degraded service on the status channel they keep; this project has none (docs/limitations.md).
