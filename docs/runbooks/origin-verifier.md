# The origin verifier cannot read its secret: `origin-verifier-errors`

| | |
| --- | --- |
| Alarm | `origin-verifier-errors`: the origin verifier could not read the secret of the edge (the metric filter `OriginVerifierFailures`, 1 minute) |
| Objective | API availability |
| In the emulator | the metric filter and its alarm run for real |

## What it means and who it affects

The origin verifier, the authorizer of every route of API Gateway, compares the `X-Origin-Verify` the edge sends with the current and the previous value of the secret `dian-einvoice/prod/edge/origin-verify`, which it reads at most every 30 s. It could not read it: it keeps the values it already holds, so a warm verifier still lets the edge in, but a new execution environment of the verifier has nothing to compare with, and once the 5 minutes API Gateway keeps each answer are over, requests through the edge may be refused (403) or fail (500). Every user of the web app and every integration is affected; requests that skip the edge are refused, as always.

## Confirm it

1. **What the verifier wrote**: the line `edge.secret` with its error.
   - Emulator: `make cloud-errors FN=origin-verifier MINUTES=30`
   - AWS: `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-origin-verifier --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "edge.secret" }'`
2. **The secret exists and is readable**: not deleted, not scheduled for deletion, its current version there.
   - Emulator: the secret in the web console of the emulator
   - AWS: `aws secretsmanager describe-secret --secret-id dian-einvoice/prod/edge/origin-verify --query "[DeletedDate,VersionIdsToStages]"`
3. **The edge answers**, and API Gateway refuses whoever skips it.
   - Emulator: `make cloud-deploy STEP=smoke`
   - AWS: `sh scripts/deploy.sh prod smoke`

## Probable causes

- **Secrets Manager unreachable** from the verifier (it runs outside the VPC, on the public endpoint): a regional event of AWS.
- **The secret deleted** or scheduled for deletion, by hand or by a stack (the stack security keeps it).
- **The role of the verifier** lost its permission on the secret (the stack compute changed).
- **Throttling of Secrets Manager**: many new environments of the verifier at once, each reading it.

## Respond

4. **A secret scheduled for deletion**: restore it; its values come back as they were, so the distribution and the verifier agree again.
   - Emulator: none, the emulator keeps the secrets of the stack security.
   - AWS: `aws secretsmanager restore-secret --secret-id dian-einvoice/prod/edge/origin-verify`
5. **A role or a stack changed**: deploy the stacks from the commit last deployed, which puts the policy back (the workflow shows the differences first).
6. **A value the distribution and the verifier disagree on** (a rotation half done): finish it ([rotate-secrets.md](rotate-secrets.md), the secret of the edge).

## Verify the recovery

7. **The edge answers**, the verifier writes no more `edge.secret` failures, and the alarm is back to `OK`.
   - Emulator: `make cloud-deploy STEP=smoke`, then `make cloud-alarms`
   - AWS: `sh scripts/deploy.sh prod smoke`, then `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-origin-verifier-errors --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- A secret deleted by hand: nothing outside CloudFormation and the rotation procedure touches it.
- If refusals reached users, they count against the error budget of the API.
