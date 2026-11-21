# The API answers errors: `api-server-errors`

| | |
| --- | --- |
| Alarm | `api-server-errors`: 5 or more 5xx at API Gateway in 5 minutes |
| Objective | API availability: 99.9% of the requests without a 5xx, 43 minutes of error budget a month |
| In the emulator | the native metrics of API Gateway are not published, so the alarm stays without data; the errors show in the logs of the function api |

## What it means and who it affects

Requests of the web app and of the integrations through the edge get a 5xx: their users cannot sign in, issue or download, and an integration retrying an issue with its Idempotency-Key gets the same answer each time. Every answer of the API carries `X-Correlation-Id`, and every problem `correlation_id`, which a customer can send to support ([customer-report.md](customer-report.md)).

## Confirm it

1. **Which routes fail, with which status**, in the access log of API Gateway (route, status, latency, error of the integration).
   - Emulator: `make cloud-errors FN=api MINUTES=30` (the emulator writes no access log of API Gateway)
   - AWS: `aws logs filter-log-events --log-group-name /aws/http-api/dian-einvoice-prod-functions --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.status = 5* }'`
2. **What the function wrote** for those requests, and when it began.
   - Emulator: `make cloud-errors FN=api MINUTES=30`
   - AWS: the saved query `dian-einvoice/prod/errors-by-function`, then `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-api --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "http.request" && $.detail.status >= 500 }'`
3. **Whether it follows a deployment.**
   - Emulator: `make cloud-deploy STEP=versions`
   - AWS: `sh scripts/deploy.sh prod versions`, and the runs of the deployment workflow.

## Probable causes

| What the access log says | Cause | Go to |
| --- | --- | --- |
| `integrationError`, status 502 or 500, and errors in the function | The function crashed or timed out (28 s) | [function-errors.md](function-errors.md) |
| Status 503 or 429 at API Gateway, nothing in the function | Throttled at its reserved concurrency | [function-throttles.md](function-throttles.md) |
| `authorizerError`, status 500 | The origin verifier failed | [origin-verifier.md](origin-verifier.md) |
| 500 of the application with a database error | Connections, capacity, credentials | [database-capacity.md](database-capacity.md), [rotate-secrets.md](rotate-secrets.md) |
| 500 of the application from a minute on, one class of error | A release | step 4 |

## Respond

4. **A release: go back to the image before it**, by its digest (the database stays as it is, forward only), and to the web app before it if the release changed it too.
   - Emulator: `make cloud-deploy STEP=rollback-image IMAGE=<an earlier image, from make cloud-deploy STEP=versions>`, and `make cloud-deploy STEP=rollback-panel`
   - AWS: `sh scripts/deploy.sh prod rollback-image <an earlier image, from sh scripts/deploy.sh prod versions>`, and `sh scripts/deploy.sh prod rollback-panel`
5. **Any other cause**: its runbook, from the table.

## Verify the recovery

6. **The API answers through the edge**, and API Gateway still refuses whoever skips it.
   - Emulator: `make cloud-deploy STEP=smoke`
   - AWS: `sh scripts/deploy.sh prod smoke`
7. **No 5xx in the next minutes**, and the alarm back to `OK`.
   - Emulator: `make cloud-errors FN=api MINUTES=10`, then `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-api-server-errors --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- The requests that failed, against the error budget of the month: the dashboard counts them (5xx of API Gateway).
- Requests issued during the errors may have been retried by their clients with the same Idempotency-Key: they acted once.
