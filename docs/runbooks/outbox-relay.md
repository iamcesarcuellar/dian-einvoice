# The outbox waits or the relay stops: `relay-not-running`, `outbox-lag`

| | |
| --- | --- |
| Alarms | `relay-not-running`: no run of the relay for 3 minutes (missing data breaches); `outbox-lag`: an event unpublished for more than 2 minutes, for 2 runs of the relay |
| Objective | Time to the answer of the DIAN |
| In the emulator | both alarms read metric filters over the logs of the relay, which the emulator evaluates for real |

## What it means and who it affects

Every change records its events in the outbox table in its own transaction. The writer publishes them to the topic right after its commit (the fast path); the relay function, once a minute, publishes what was left behind (docs/infrastructure.md, the outbox without a relay that runs all the time). When the relay does not run, or events wait although it runs, the steps those events start do not happen: an invoice issued stays numbered and signed but is never transmitted, a delivery never starts, a webhook is never sent. The companies see their documents stuck in a state, with no error. Nothing is lost: the events stay in the table until they are published.

- `relay-not-running` alone: the fast path still publishes almost everything; only what a writer left behind waits.
- `outbox-lag`: events do wait, with or without the relay: the topic refuses them, or the relay cannot keep up.

## Confirm it

1. **The state of the outbox**: how many events wait, how old the oldest is and of which types, what was published lately, when the relay last ran.
   - Emulator: `make cloud-artisan ARGS="outbox:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"outbox:status"' /dev/stdout`
2. **The schedule and the function of the relay.**
   - Emulator: `make cloud-functions`
   - AWS: `aws scheduler get-schedule --name dian-einvoice-prod-relay --query "[State,ScheduleExpression]"`, and `aws lambda get-function --function-name dian-einvoice-prod-relay --query "[Configuration.State,Configuration.LastUpdateStatus,Concurrency]"`
3. **Its errors**: a run that fails writes its error; one that never starts writes nothing.
   - Emulator: `make cloud-errors FN=relay MINUTES=30`
   - AWS: the saved query `dian-einvoice/prod/errors-by-function`, and `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-relay --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "outbox.relay_run" }'` for its last runs.

## Probable causes

| What you see | Cause |
| --- | --- |
| No run in its log, the schedule `DISABLED` or missing | The schedule was turned off or deleted outside CloudFormation, or the role of the schedules lost its permission |
| Runs that fail: the database (connections, credentials) | [database-capacity.md](database-capacity.md), [rotate-secrets.md](rotate-secrets.md) |
| Runs that fail: SNS refuses the publish (`AuthorizationError`, KMS) | The topic, its policy or the managed policy `publish-document-events` changed |
| Runs that succeed, and the lag grows | Many events at once: the relay publishes for 40 s per run; or the fast path fails for every writer (the same refusal of SNS) |
| Throttles of the relay | Its reserved concurrency (1) taken by a run that hangs: [function-throttles.md](function-throttles.md) |
| `paused by the demo seeder: yes` | Only in the emulated cloud: `make cloud-demo` holds the relay until it ends |

## Respond

4. **Publish what waits now**, by hand: the same command the relay runs, in the console function, which may publish to the topic. Repeat it while the lag stays.
   - Emulator: `make cloud-artisan ARGS="outbox:publish-pending"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-relay --cli-binary-format raw-in-base64-out --payload '"outbox:publish-pending"' /dev/stdout` (the relay function itself), or the console function with the same payload.
5. **Put the relay back as its template declares it**: a schedule or a function changed outside CloudFormation is restored by deploying the functions with the image they run.
   - Emulator: `make cloud-deploy STEP=functions`
   - AWS: `sh scripts/deploy.sh prod functions <the image the functions run, from sh scripts/deploy.sh prod versions>`, or the deployment workflow.
6. **SNS refuses**: the cause is in the stack messaging or its key; deploy it from the commit last deployed with the workflow, which shows the differences first.

## Verify the recovery

7. **Nothing waits, and the relay runs once a minute.**
   - Emulator: `make cloud-artisan ARGS="outbox:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"outbox:status"' /dev/stdout`
8. **The chains moved on**: nothing stuck in the chain for longer than its retries.
   - Emulator: `make cloud-artisan ARGS="chain:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"chain:status"' /dev/stdout`
9. **The alarms are back to `OK`**: `relay-not-running` after a run, `outbox-lag` after two runs without lag.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-relay-not-running dian-einvoice-prod-outbox-lag --query "MetricAlarms[].[AlarmName,StateValue]"`

## Afterwards

- How long the events waited, and which documents: those stuck for more than 5 minutes count against the objective.
- A schedule turned off by hand is drift: CloudFormation is the only one that changes it.
- `RelayPublishedEvents` above zero most minutes means the fast path is failing: find why, since the relay alone adds up to a minute to every step.
