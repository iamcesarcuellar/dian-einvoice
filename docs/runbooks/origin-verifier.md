# The origin verifier cannot read its secret: `origin-verifier-errors`

**English** · [Español](../es/runbooks/origin-verifier.md)

| | |
| --- | --- |
| Alarm | `origin-verifier-errors`: the origin verifier could not read the edge secret (the metric filter `OriginVerifierFailures`, 1 minute) |
| Service objective | API availability |
| In the emulator | The metric filter and its alarm run for real. |

## What it means and who it affects

The origin verifier is the authorizer on every API Gateway route. It compares the `X-Origin-Verify` header the edge sends with the current and previous values of the secret `dian-einvoice/prod/edge/origin-verify`. It keeps the values it reads for 30 s, and reads them again sooner, at most every 5 s, when a request carries a value it does not hold.

This time it could not read the secret. It keeps the values it already holds, so a warm verifier still lets the edge in. A new verifier execution environment, though, has nothing to compare with. API Gateway keeps each authorizer response for 5 minutes. Once that time is over, requests through the edge may be refused (403) or fail (500).

Every web app user and every integration is affected. Requests that skip the edge are refused, as always.

## Confirm it

1. **What the verifier logged**: the `edge.secret` line with its error.
   - Emulator: `make cloud-errors FN=origin-verifier MINUTES=30`
   - AWS: `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-origin-verifier --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "edge.secret" }'`
2. **The secret exists and is readable**: not deleted, not scheduled for deletion, with its current version in place.
   - Emulator: the secret in the emulator's web console
   - AWS: `aws secretsmanager describe-secret --secret-id dian-einvoice/prod/edge/origin-verify --query "[DeletedDate,VersionIdsToStages]"`
3. **The edge responds**, and API Gateway refuses requests that skip it.
   - Emulator: `make cloud-deploy STEP=smoke`
   - AWS: `sh scripts/deploy.sh prod smoke`

## Probable causes

- **Secrets Manager is unreachable** from the verifier, which runs outside the VPC and uses the public endpoint: a regional AWS event.
- **The secret was deleted** or scheduled for deletion, by hand or by a stack (the security stack holds it).
- **The verifier's role** lost its permission on the secret (the compute stack changed).
- **Secrets Manager throttling**: many new verifier environments start at once, and each one reads the secret.

## Respond

4. **A secret scheduled for deletion**: restore it. Its values come back as they were, so the distribution and the verifier agree again.
   - Emulator: none; the emulator keeps the security stack's secrets.
   - AWS: `aws secretsmanager restore-secret --secret-id dian-einvoice/prod/edge/origin-verify`
5. **A role or a stack changed**: deploy the stacks from the last deployed commit, which restores the policy. The workflow shows the differences first.
6. **The distribution and the verifier disagree on the value** (a half-finished rotation): finish the rotation (see "The edge secret" in [rotate-secrets.md](rotate-secrets.md)).

## Verify the recovery

7. **The edge responds**, the verifier logs no more `edge.secret` failures, and the alarm is back to `OK`.
   - Emulator: `make cloud-deploy STEP=smoke`, then `make cloud-alarms`
   - AWS: `sh scripts/deploy.sh prod smoke`, then `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-origin-verifier-errors --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- If someone deleted the secret by hand: nothing except CloudFormation and the rotation procedure may touch it.
- Refusals that reached users count against the API's error budget.
