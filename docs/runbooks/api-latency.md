# The API is slow: `api-latency`

| | |
| --- | --- |
| Alarm | `api-latency`: the 99th percentile of the latency at API Gateway above 2 s for 15 minutes |
| Objective | API latency: 99% of the requests within 1 second |
| In the emulator | the native metrics of API Gateway are not published; the times of each request are in the lines `http.request` of the function api |

## What it means and who it affects

One request in a hundred takes more than twice the objective: the web app feels slow, and integrations with a short timeout may give up and retry (with their Idempotency-Key, so they act once). Twice the objective for a quarter of an hour, so a burst of cold starts after a deployment does not set it off.

## Confirm it

1. **Which requests are slow**: route, status, time and journey, slowest first.
   - Emulator: `make cloud-errors FN=api MINUTES=30` for errors, and `make cloud-follow ID=<correlation id of a slow request> HOURS=1` for one of them
   - AWS: the saved query `dian-einvoice/prod/slow-requests`
2. **Where the time goes**: in the function (the `duration_ms` of its lines `http.request`), or before it, at API Gateway and in the origin verifier (`integrationLatency` and `authorizerLatency` of the access log of API Gateway, in `/aws/http-api/dian-einvoice-prod-functions`).
   - Emulator: `make cloud-functions`
   - AWS: `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-api --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "http.request" && $.duration_ms > 2000 }'`; a slow request that is fast in the function waited before it.
3. **The database**, the first suspect of a slow request.
   - Emulator: `make cloud-artisan ARGS="db:connections"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"db:connections"' /dev/stdout`, and the widget "Database: capacity" of the dashboard.

## Probable causes

- **The database at its capacity** (4 ACU), or slow queries (its slow query log, kept a year): [database-capacity.md](database-capacity.md).
- **Cold starts**: many new execution environments after a deployment or a burst of traffic (a cold start of the image takes seconds the first time AWS pulls a new image).
- **A dependency that waits**: S3, DynamoDB, SNS or KMS slow inside a request; in prod, the way out through the NAT gateway for the services without a gateway endpoint.
- **Throttles**: requests that wait for an environment: [function-throttles.md](function-throttles.md).
- **A release** with a slower query or more work per request.

## Respond

4. **The database**: its runbook ([database-capacity.md](database-capacity.md)).
5. **A release**: back to the image before it.
   - Emulator: `make cloud-deploy STEP=rollback-image IMAGE=<an earlier image, from make cloud-deploy STEP=versions>`
   - AWS: `sh scripts/deploy.sh prod rollback-image <an earlier image>`
6. **Cold starts that keep happening** (traffic that comes and goes): more memory for the function api buys CPU and shorter cold starts (`memorySize` in the serverless service), a change deployed with the workflow.

## Verify the recovery

7. **The slow requests are gone** from the saved query over the last 15 minutes, and the alarm is back to `OK` after its three periods.
   - Emulator: `make cloud-alarms`
   - AWS: the saved query `dian-einvoice/prod/slow-requests`, then `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-api-latency --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- The 30-day percentile against the objective: the widget "API latency" of the dashboard.
- A slow query found: its index, in a migration that only adds (make migrations-check).
