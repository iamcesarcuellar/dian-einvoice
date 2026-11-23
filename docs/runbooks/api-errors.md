# The API returns errors: `api-server-errors`

| | |
| --- | --- |
| Alarm | `api-server-errors`: 5 or more 5xx at API Gateway in 5 minutes |
| Service objective | API availability: 99.9% of API requests return no 5xx, an error budget of 43 minutes a month |
| In the emulator | API Gateway's native metrics are not published, so the alarm gets no data; the errors show in the api function's logs |

## What it means and who it affects

Requests through the edge, from the web app and from integrations, get a 5xx. Their users cannot sign in, issue or download. An integration that retries an issue with its Idempotency-Key gets the same response each time.

Every API response carries `X-Correlation-Id`, and every problem response carries `correlation_id`. A customer can send that id to support ([customer-report.md](customer-report.md)).

## Confirm it

1. **Which routes fail, and with which status.** API Gateway's access log has each request's route, status, latency and integration error.
   - Emulator: `make cloud-errors FN=api MINUTES=30` (the emulator writes no API Gateway access log)
   - AWS: `aws logs filter-log-events --log-group-name /aws/http-api/dian-einvoice-prod-functions --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.status = 5* }'`
2. **What the function logged** for those requests, and when it started.
   - Emulator: `make cloud-errors FN=api MINUTES=30`
   - AWS: the saved query `dian-einvoice/prod/errors-by-function`, then `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-api --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "http.request" && $.detail.status >= 500 }'`
3. **Whether it started after a deployment.**
   - Emulator: `make cloud-deploy STEP=versions`
   - AWS: `sh scripts/deploy.sh prod versions`, and the deployment workflow's runs.

## Probable causes

| What the access log says | Cause | Go to |
| --- | --- | --- |
| `integrationError`, status 502 or 500, and errors in the function | The function crashed or timed out (28 s) | [function-errors.md](function-errors.md) |
| Status 503 or 429 at API Gateway, nothing in the function | The function was throttled at its reserved concurrency | [function-throttles.md](function-throttles.md) |
| `authorizerError`, status 500 | The origin verifier failed | [origin-verifier.md](origin-verifier.md) |
| An application 500 with a database error | Connections, capacity or credentials | [database-capacity.md](database-capacity.md), [rotate-secrets.md](rotate-secrets.md) |
| Application 500s from a given minute on, all of one error class | A release | step 4 |

## Respond

4. **A release: roll back to the previous image**, by its digest. The database stays as it is, since migrations only go forward. If the release changed the web app too, roll it back as well.
   - Emulator: `make cloud-deploy STEP=rollback-image IMAGE=<an earlier image, from make cloud-deploy STEP=versions>`, and `make cloud-deploy STEP=rollback-panel`
   - AWS: `sh scripts/deploy.sh prod rollback-image <an earlier image, from sh scripts/deploy.sh prod versions>`, and `sh scripts/deploy.sh prod rollback-panel`
5. **Any other cause**: follow its runbook, from the table.

## Verify the recovery

6. **The API responds through the edge**, and API Gateway still refuses requests that skip the edge.
   - Emulator: `make cloud-deploy STEP=smoke`
   - AWS: `sh scripts/deploy.sh prod smoke`
7. **No 5xx over the next few minutes**, and the alarm is back to `OK`.
   - Emulator: `make cloud-errors FN=api MINUTES=10`, then `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-api-server-errors --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- Count the failed requests against the month's error budget. The dashboard counts them (API Gateway 5xx).
- Clients may have retried requests made during the errors with the same Idempotency-Key. Each of those requests took effect once.
