# Events wait in the outbox, or the relay stops: `relay-not-running`, `outbox-lag`

**English** · [Español](../es/runbooks/outbox-relay.md)

| | |
| --- | --- |
| Alarms | `relay-not-running`: no relay run for 3 minutes (missing data breaches); `outbox-lag`: an event unpublished for more than 2 minutes, over 2 relay runs |
| Service objective | Time to the DIAN response |
| In the emulator | Both alarms read metric filters over the relay's logs, which the emulator evaluates for real. |

## What it means and who it affects

Every change records its events in the outbox table, in the same transaction as the change. The writer publishes them to the topic right after its commit (the fast path). Once a minute, the relay function publishes whatever was left behind. See docs/infrastructure.md for how the outbox works without a relay that runs all the time.

When the relay does not run, or events wait even though it runs, the steps those events start do not happen:

- An issued invoice stays numbered and signed, but is never transmitted.
- A delivery never starts.
- A webhook is never sent.

The companies see their documents stuck in one state, with no error. Nothing is lost: the events stay in the table until they are published.

- `relay-not-running` alone: the fast path still publishes almost everything. Only what a writer left behind waits.
- `outbox-lag`: events really are waiting, with or without the relay. Either the topic refuses them or the relay cannot keep up.

## Confirm it

1. **The outbox's state**: how many events wait and of which types, how old the oldest is, what was published lately, and when the relay last ran.
   - Emulator: `make cloud-artisan ARGS="outbox:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"outbox:status"' /dev/stdout`
2. **The relay's schedule and function.**
   - Emulator: `make cloud-functions`
   - AWS: `aws scheduler get-schedule --name dian-einvoice-prod-relay --query "[State,ScheduleExpression]"`, and `aws lambda get-function --function-name dian-einvoice-prod-relay --query "[Configuration.State,Configuration.LastUpdateStatus,Concurrency]"`
3. **Its errors**: a run that fails logs its error, and a run that never starts logs nothing.
   - Emulator: `make cloud-errors FN=relay MINUTES=30`
   - AWS: the saved query `dian-einvoice/prod/errors-by-function`, and `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-relay --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "outbox.relay_run" }'` for its last runs.

## Probable causes

| What you see | Cause |
| --- | --- |
| No run in its log; the schedule is `DISABLED` or missing | The schedule was turned off or deleted outside CloudFormation, or the schedules' role lost its permission |
| Runs that fail: the database (connections, credentials) | [database-capacity.md](database-capacity.md), [rotate-secrets.md](rotate-secrets.md) |
| Runs that fail: SNS refuses the publish (`AuthorizationError`, KMS) | The topic, its policy or the managed policy `publish-document-events` changed |
| Runs that succeed, and the lag grows | Many events at once, since the relay publishes for 40 s per run. Or the fast path fails for every writer (the same SNS refusal) |
| Relay throttles | A run that hangs holds the relay's reserved concurrency (1): [function-throttles.md](function-throttles.md) |
| `paused by the demo seeder: yes` | Only in the emulated cloud: `make cloud-demo` holds the relay until it ends |

## Respond

4. **Publish what waits now**, by hand. Run the relay's own command in the console function, which may publish to the topic. Repeat it while the lag lasts.
   - Emulator: `make cloud-artisan ARGS="outbox:publish-pending"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-relay --cli-binary-format raw-in-base64-out --payload '"outbox:publish-pending"' /dev/stdout` (the relay function itself), or the console function with the same payload.
5. **Restore the relay as its template declares it.** Deploying the functions with the image they run restores a schedule or a function changed outside CloudFormation.
   - Emulator: `make cloud-deploy STEP=functions`
   - AWS: `sh scripts/deploy.sh prod functions <the image the functions run, from sh scripts/deploy.sh prod versions>`, or the deployment workflow.
6. **SNS refuses**: the cause is in the messaging stack or its key. Deploy that stack from the last deployed commit with the workflow, which shows the differences first.

## Verify the recovery

7. **Nothing waits, and the relay runs once a minute.**
   - Emulator: `make cloud-artisan ARGS="outbox:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"outbox:status"' /dev/stdout`
8. **The chains moved on**: nothing is stuck in the chain for longer than its retries.
   - Emulator: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status"' /dev/stdout`
9. **The alarms are back to `OK`**: `relay-not-running` after one run, and `outbox-lag` after two runs without lag.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-relay-not-running dian-einvoice-prod-outbox-lag --query "MetricAlarms[].[AlarmName,StateValue]"`

## Afterwards

- How long the events waited, and for which documents. Those stuck for more than 5 minutes count against the service objective.
- A schedule turned off by hand is drift: only CloudFormation changes it.
- `RelayPublishedEvents` above zero most minutes means the fast path is failing. Find out why: the relay alone adds up to a minute to every step.
