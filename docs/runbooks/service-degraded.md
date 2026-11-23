# The service is degraded: `service-degraded` (prod)

| | |
| --- | --- |
| Alarm | `service-degraded`, a composite alarm: any of `api-server-errors`, `api-latency`, `chain-dead-letters`, `chain-queue-age`, `dian-error-rate`, `relay-not-running`, `origin-verifier-errors`, `function-errors` in `ALARM` |
| Service objectives | API availability, API latency, Time to the DIAN response |
| In the emulator | Prod only: the emulator runs no composite alarm actions (docs/limitations.md). The alarms under it run there as their runbooks say. |

## What it means and who it affects

This is the one alarm to page on. Some part of the service the companies depend on is failing, whichever part it is. The alarms under it that are in `ALARM` say which part, and each one has its runbook.

While it lasts, the companies may be unable to issue, to get their invoices validated by the DIAN, or to deliver them to their customers.

## Confirm it

1. **Which alarms under it are in `ALARM`**, and since when. They say where to go.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-name-prefix dian-einvoice-prod- --state-value ALARM --query "MetricAlarms[].[AlarmName,StateUpdatedTimestamp,StateReason]" --output table`
2. **The dashboard** `dian-einvoice-prod-operations` shows the API, the edge, the chain and the DIAN on one page. Also check whether the problem began with a deployment (`sh scripts/deploy.sh prod versions`).

## Probable causes

Each alarm under it has its causes in its own runbook: [api-errors.md](api-errors.md), [api-latency.md](api-latency.md), [dead-letters.md](dead-letters.md), [queue-backlog.md](queue-backlog.md), [dian-errors.md](dian-errors.md), [outbox-relay.md](outbox-relay.md), [origin-verifier.md](origin-verifier.md), [function-errors.md](function-errors.md).

Several alarms at once usually share one cause: a deployment, the database, the network's outbound path, or a regional AWS event.

## Respond

3. **One person leads**, says so in the chat, and gives the time of the next update.
4. **A deployment in the last hour**: roll back first, ask later ([api-errors.md](api-errors.md), step 4).
5. **Otherwise**, follow the runbook of the alarm that went off first.
6. **Tell the companies** when they cannot issue or their invoices wait, and again when it is over.

## Verify the recovery

7. **Every alarm under it is back to `OK`**, and then this one.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-types CompositeAlarm --alarm-names dian-einvoice-prod-service-degraded --query "CompositeAlarms[].[StateValue,StateReason]"`

## Afterwards

- A blameless review within a few days, written like [drill-report.md](drill-report.md): what happened, the timeline, the impact, what worked and what to improve.
- The minutes it lasted, against each service objective's budget.
