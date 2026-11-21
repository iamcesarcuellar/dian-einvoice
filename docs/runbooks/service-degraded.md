# The service is degraded: `service-degraded` (prod)

| | |
| --- | --- |
| Alarm | `service-degraded`, composite: any of `api-server-errors`, `api-latency`, `chain-dead-letters`, `chain-queue-age`, `dian-error-rate`, `relay-not-running`, `origin-verifier-errors`, `function-errors` in `ALARM` |
| Objectives | API availability, API latency, Time to the answer of the DIAN |
| In the emulator | prod only: the emulator runs no action of a composite alarm (docs/limitations.md); its children run there as their runbooks say |

## What it means and who it affects

The one alarm to page on: some part of the service the companies depend on is failing, whatever part it is. The alarms under it in `ALARM` say which; each has its runbook. While it lasts, the companies may not be able to issue, see their invoices validated by the DIAN, or deliver them to their customers.

## Confirm it

1. **Which alarms under it are in `ALARM`**, and since when: they say where to go.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-name-prefix dian-einvoice-prod- --state-value ALARM --query "MetricAlarms[].[AlarmName,StateUpdatedTimestamp,StateReason]" --output table`
2. **The dashboard** `dian-einvoice-prod-operations`: the API, the edge, the chain and the DIAN on one page, and whether it began with a deployment (`sh scripts/deploy.sh prod versions`).

## Probable causes

The causes of each alarm under it, in its runbook: [api-errors.md](api-errors.md), [api-latency.md](api-latency.md), [dead-letters.md](dead-letters.md), [queue-backlog.md](queue-backlog.md), [dian-errors.md](dian-errors.md), [outbox-relay.md](outbox-relay.md), [origin-verifier.md](origin-verifier.md), [function-errors.md](function-errors.md). Several at once usually share one cause: a deployment, the database, the way out of the network, a regional event of AWS.

## Respond

3. **One person leads**, says so in the chat, and gives the time of the next update.
4. **A deployment in the last hour**: go back first, ask later ([api-errors.md](api-errors.md), step 4).
5. **Otherwise**, the runbook of the alarm that began first.
6. **Tell the companies** when they cannot issue or their invoices wait, and again when it is over.

## Verify the recovery

7. **Every alarm under it back to `OK`**, then this one.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-types CompositeAlarm --alarm-names dian-einvoice-prod-service-degraded --query "CompositeAlarms[].[StateValue,StateReason]"`

## Afterwards

- A review without blame within a few days, as [drill-report.md](drill-report.md) is written: what happened, the timeline, the impact, what worked and what to improve.
- The minutes it lasted, against the budgets of the objectives.
