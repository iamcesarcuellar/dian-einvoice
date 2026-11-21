# Functions held back: `function-throttles`

| | |
| --- | --- |
| Alarm | `function-throttles`: one or more throttled invocations of any function in 5 minutes (metric math over the `Throttles` of every function) |
| Objectives | API availability, API latency |
| In the emulator | metric math and the native metrics of Lambda are not evaluated; the emulator applies reserved concurrency, so `make cloud-functions` shows each limit |

## What it means and who it affects

Lambda refused invocations because a function ran as many execution environments as it may: its reserved concurrency (api 40, chain 10, webhooks 5, relay 1, scheduler 2, console 2, origin verifier 10, notifier 2), or the quota of concurrent executions of the account when the stage deploys without reserving (`reservedConcurrency: unreserved`, docs/deployment.md). For the API, requests answer 429 or 503 through the edge; for the queues, messages wait longer; for the relay or the scheduler, a minute is skipped. The reserved concurrency also caps the connections to the database (60 in all, of the 135 the database takes at 4 ACU), so raising it is a decision about the database too.

## Confirm it

1. **Which function, and its limit.**
   - Emulator: `make cloud-functions`
   - AWS: `aws cloudwatch get-metric-statistics --namespace AWS/Lambda --metric-name Throttles --dimensions Name=FunctionName,Value=dian-einvoice-prod-<function> --start-time <ISO time of an hour ago> --end-time <ISO time now> --period 300 --statistics Sum`, then `aws lambda get-function-concurrency --function-name dian-einvoice-prod-<function>`
2. **The concurrency of the account**: its quota and what stays unreserved (at least 100 must).
   - Emulator: `make cloud-functions` (its last line)
   - AWS: `aws lambda get-account-settings --query AccountLimit`, and `aws service-quotas get-service-quota --service-code lambda --quota-code L-B99A9384`
3. **Whether it is traffic or slowness**: more requests than usual, or the same ones taking longer (each environment is held for the duration of its invocation): the widgets "Duration of the functions" and "Concurrency" of the dashboard, and for the API the saved query `dian-einvoice/prod/slow-requests`.

## Probable causes

- **More traffic**: an integration that issues in bulk, a company that grew; or a flood (the firewall counts and blocks floods per address before the API).
- **Slower invocations**: the database ([database-capacity.md](database-capacity.md)), a dependency that waits to its timeout, cold starts after a deployment.
- **A function that hangs** until its timeout holds its environments: the relay (1) blocks itself.
- **An account whose quota is low** (new accounts start with reduced quotas, which AWS raises with use), deployed without reserved concurrency.

## Respond

4. **Slowness first**: fix what makes the invocations slow ([api-latency.md](api-latency.md), [database-capacity.md](database-capacity.md)); more concurrency for slow invocations only holds more connections.
5. **Traffic that is legitimate and lasts**: raise the reserved concurrency of the function in the serverless service (`custom.concurrency.reserved` of `infra/functions/serverless.yml`), within the budget of the database (a test holds the sum below its connections), and deploy the functions. Beyond about 95 environments for the API at 4 ACU, turn on RDS Proxy or raise the capacity of the database first (docs/infrastructure.md).
   - Emulator: `make cloud-deploy STEP=functions`
   - AWS: the deployment workflow from the commit with the change, or `sh scripts/deploy.sh prod functions <the image the functions run>`
6. **The quota of the account**: ask AWS for more concurrent executions, and deploy with reserved concurrency again once the quota leaves 100 unreserved above the sum the functions reserve (docs/deployment.md).
   - Emulator: none, the emulator has no quotas.
   - AWS: `aws service-quotas request-service-quota-increase --service-code lambda --quota-code L-B99A9384 --desired-value 1000`

## Verify the recovery

7. **No more throttles**, and the concurrency of each function below its limit.
   - Emulator: `make cloud-functions`
   - AWS: `aws cloudwatch get-metric-statistics --namespace AWS/Lambda --metric-name Throttles --dimensions Name=FunctionName,Value=dian-einvoice-prod-<function> --start-time <ISO time of 15 minutes ago> --end-time <ISO time now> --period 300 --statistics Sum`
8. **The alarm is back to `OK`.**
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-function-throttles --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- A new limit is a decision about the database: write the new sum next to the test that holds it, and why.
- A put-function-concurrency by hand is drift: the next deployment puts the value of the template back.
