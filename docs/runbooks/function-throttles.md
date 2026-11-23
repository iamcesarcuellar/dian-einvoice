# Throttled functions: `function-throttles`

| | |
| --- | --- |
| Alarm | `function-throttles`: one or more throttled invocations of any function in 5 minutes (metric math over every function's `Throttles`) |
| Service objectives | API availability, API latency |
| In the emulator | The emulator evaluates neither metric math nor Lambda's native metrics. It does apply reserved concurrency, and `make cloud-functions` shows each limit. |

## What it means and who it affects

Lambda refused invocations because a function already ran as many execution environments as it may. The limit is the function's reserved concurrency: api 40, chain 10, webhooks 5, relay 1, scheduler 2, console 2, origin verifier 10, notifier 2. When the stage deploys without reserving (`reservedConcurrency: unreserved`, docs/deployment.md), the limit is the account's quota of concurrent executions.

Who it affects:

- **The API**: requests return 429 or 503 through the edge.
- **The queues**: messages wait longer.
- **The relay or the scheduler**: a minute is skipped.

The reserved concurrency also caps the database connections: 60 in all, of the 135 the database accepts at 4 ACU. So raising it is a database decision too.

## Confirm it

1. **Which function, and its limit.**
   - Emulator: `make cloud-functions`
   - AWS: `aws cloudwatch get-metric-statistics --namespace AWS/Lambda --metric-name Throttles --dimensions Name=FunctionName,Value=dian-einvoice-prod-<function> --start-time <ISO time of an hour ago> --end-time <ISO time now> --period 300 --statistics Sum`, then `aws lambda get-function-concurrency --function-name dian-einvoice-prod-<function>`
2. **The account's concurrency**: its quota, and how much stays unreserved (at least 100 must).
   - Emulator: `make cloud-functions` (its last line)
   - AWS: `aws lambda get-account-settings --query AccountLimit`, and `aws service-quotas get-service-quota --service-code lambda --quota-code L-B99A9384`
3. **Traffic or slowness**: more requests than usual, or the same requests taking longer. Each environment is held for as long as its invocation runs. Check the dashboard's "Function duration, p95 (ms)" widget and its per-function "Concurrency" widgets, and for the API, the saved query `dian-einvoice/prod/slow-requests`.

## Probable causes

- **More traffic**: an integration that issues in bulk, or a company that grew. Or a flood: the firewall counts and blocks floods per address before they reach the API.
- **Slower invocations**: the database ([database-capacity.md](database-capacity.md)), a dependency that waits until its timeout, or cold starts after a deployment.
- **A function that hangs** until its timeout holds on to its environments. The relay, with a limit of 1, blocks itself.
- **A low account quota**, with the functions deployed without reserved concurrency. New accounts start with reduced quotas, which AWS raises with use.

## Respond

4. **Slowness first**: fix what makes the invocations slow ([api-latency.md](api-latency.md), [database-capacity.md](database-capacity.md)). More concurrency for slow invocations only holds more connections.
5. **Legitimate traffic that lasts**: raise the function's reserved concurrency in the serverless service (`custom.concurrency.reserved` in `infra/functions/serverless.yml`), then deploy the functions. Stay within the database connection budget: a test keeps the sum below the database's connections. Beyond about 95 API environments at 4 ACU, first turn on RDS Proxy or raise the database capacity (docs/infrastructure.md).
   - Emulator: `make cloud-deploy STEP=functions`
   - AWS: the deployment workflow from the commit with the change, or `sh scripts/deploy.sh prod functions <the image the functions run>`
6. **The account quota**: ask AWS for more concurrent executions. Deploy with reserved concurrency again once the quota leaves 100 unreserved above the sum the functions reserve (docs/deployment.md).
   - Emulator: none; the emulator has no quotas.
   - AWS: `aws service-quotas request-service-quota-increase --service-code lambda --quota-code L-B99A9384 --desired-value 1000`

## Verify the recovery

7. **No more throttles**, and each function's concurrency below its limit.
   - Emulator: `make cloud-functions`
   - AWS: `aws cloudwatch get-metric-statistics --namespace AWS/Lambda --metric-name Throttles --dimensions Name=FunctionName,Value=dian-einvoice-prod-<function> --start-time <ISO time of 15 minutes ago> --end-time <ISO time now> --period 300 --statistics Sum`
8. **The alarm is back to `OK`.**
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-function-throttles --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- A new limit is a database decision: write the new sum, and why, next to the test that holds it.
- A put-function-concurrency by hand is drift: the next deployment restores the template's value.
