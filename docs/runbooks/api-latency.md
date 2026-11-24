# The API is slow: `api-latency`

**English** · [Español](../es/runbooks/api-latency.md)

| | |
| --- | --- |
| Alarm | `api-latency`: the 99th percentile of API Gateway latency is above 2 s for 15 minutes |
| Service objective | API latency: 99% of API requests get a response within 1 second |
| In the emulator | API Gateway's native metrics are not published; each request's times are in the api function's `http.request` lines |

## What it means and who it affects

One request in a hundred takes more than twice the service objective. The web app feels slow. Integrations with a short timeout may give up and retry; with their Idempotency-Key, the retries take effect once.

The alarm waits for twice the objective over a quarter of an hour, so a burst of cold starts after a deployment does not set it off.

## Confirm it

1. **Which requests are slow**: route, status, time and journey, slowest first.
   - Emulator: `make cloud-errors FN=api MINUTES=30` for the errors, and `make cloud-follow ID=<correlation id of a slow request> HOURS=1` for one slow request
   - AWS: the saved query `dian-einvoice/prod/slow-requests`
2. **Where the time goes**: in the function, or before it, at API Gateway and in the origin verifier. The function's time is the `duration_ms` of its `http.request` lines. The time before it shows in API Gateway's access log (`integrationLatency` and `authorizerLatency`, in `/aws/http-api/dian-einvoice-prod-functions`).
   - Emulator: `make cloud-functions`
   - AWS: `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-api --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "http.request" && $.duration_ms > 2000 }'`. A slow request that is fast in the function waited before it.
3. **The database**, the first suspect for a slow request.
   - Emulator: `make cloud-artisan ARGS="db:connections"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"db:connections"' /dev/stdout`, and the dashboard's "Database: capacity (ACU)" widget.

## Probable causes

- **The database at its maximum capacity** (4 ACU), or slow queries (see its slow query log, kept for a year): [database-capacity.md](database-capacity.md).
- **Cold starts**: many new execution environments after a deployment or a burst of traffic. A cold start takes seconds the first time AWS pulls a new image.
- **A slow dependency**: S3, DynamoDB, SNS or KMS is slow inside a request. In prod, also the outbound path through the NAT gateway, for the services without a gateway endpoint.
- **Throttles**: requests that wait for an execution environment: [function-throttles.md](function-throttles.md).
- **A release** with a slower query or more work per request.

## Respond

4. **The database**: follow its runbook ([database-capacity.md](database-capacity.md)).
5. **A release**: roll back to the previous image.
   - Emulator: `make cloud-deploy STEP=rollback-image IMAGE=<an earlier image, from make cloud-deploy STEP=versions>`
   - AWS: `sh scripts/deploy.sh prod rollback-image <an earlier image>`
6. **Cold starts that keep happening** (traffic that comes and goes): give the api function more memory (`memorySize` in the serverless service). More memory buys CPU and shorter cold starts. Deploy the change with the workflow.

## Verify the recovery

7. **The slow requests are gone** from the saved query over the last 15 minutes, and the alarm is back to `OK` after its three periods.
   - Emulator: `make cloud-alarms`
   - AWS: the saved query `dian-einvoice/prod/slow-requests`, then `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-api-latency --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- Compare the 30-day percentile with the service objective, in the dashboard's "API latency (ms)" widget.
- If you found a slow query, add its index in a migration that only adds (`make migrations-check`).
