# Rotate a secret out of schedule

A procedure, without an alarm: when a secret may have leaked (in a log, a screenshot, a laptop that was lost, a person who left), or before its schedule for any other reason. Each secret has its own procedure, because each one reaches its users in its own way. Rotate one at a time, and say in the chat which and when.

| Secret | Who uses it | Rotated on schedule | Out of schedule |
| --- | --- | --- | --- |
| `dian-einvoice/prod/edge/origin-verify` | The distribution sends it to API Gateway; the origin verifier checks it | no: by this procedure, whenever needed | [below](#the-secret-of-the-edge) |
| `dian-einvoice/prod/alerts/chat-webhook` | The notifier posts the alarms to its address | no: the chat issues it | [below](#the-address-of-the-chat-webhook) |
| `dian-einvoice/prod/database/application` | Every function but the console | every 30 days, alternating users | [below](#the-credentials-of-the-database) |
| `dian-einvoice/prod/database/migrations` | The console function, and the rotation functions | every 30 days, a single user | [below](#the-credentials-of-the-database) |
| `dian-einvoice/prod/app` (`APP_KEY`) | Every function: it encrypts stored values | no | not supported: it would need every encrypted value re-encrypted first (docs/limitations.md) |

## The secret of the edge

The origin verifier accepts the current and the previous version of the secret, so a rotation goes without a cut as long as no second rotation drops a value an edge location still sends (docs/infrastructure.md, the origin verifier).

1. **A new value as the current version**; the one it replaces stays valid as the previous one. Note the version id it answers.
   - Emulator: `make cloud-rotate SECRET=edge` does steps 1 to 4: the new value, the stack edge deployed with it, and the smoke test.
   - AWS: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/edge/origin-verify --secret-string "$(openssl rand -hex 32)"`
2. **Wait 30 s**, the time the verifier keeps the values (a value it does not hold makes it read the secret again at once).
3. **Deploy the edge with that version**: CloudFormation resolves the new value and CloudFront sends it as its edge locations take the new configuration; meanwhile the others send the previous value, still valid.
   - Emulator: part of `make cloud-rotate SECRET=edge`; alone, `make cloud-deploy STEP=edge`
   - AWS: `ORIGIN_VERIFY_VERSION=<version id> sh scripts/deploy.sh prod edge` with the variables of the account (docs/deployment.md), or the deployment workflow.
4. **Wait until the distribution is deployed**, and 5 minutes more (the cache of the authorizer), before any other rotation; then check that the edge answers and that API Gateway refuses whoever skips it.
   - Emulator: part of `make cloud-rotate SECRET=edge`; alone, `make cloud-deploy STEP=smoke`
   - AWS: `aws cloudfront wait distribution-deployed --id "$(aws ssm get-parameter --name /dian-einvoice/prod/edge/distribution-id --query Parameter.Value --output text)"`, then `sh scripts/deploy.sh prod smoke`
5. **If the old value leaked**, rotate once more after step 4: the second rotation drops it from the previous version too.

## The address of the chat webhook

The address is a credential: whoever holds it posts to the chat of the operators.

1. **Ask the chat for a new address**, and revoke the old one there.
2. **Put it in the secret**; the notifier reads it within 5 minutes, and never logs it.
   - Emulator: `make cloud-rotate SECRET=chat-webhook URL=<address>`
   - AWS: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/alerts/chat-webhook --secret-string '{"url":"<address>"}'`
3. **Check that a notification arrives**, after 5 minutes: [notifier.md](notifier.md), step 7.

## The credentials of the database

The rotation functions of AWS rotate both identities every 30 days. Out of schedule, ask Secrets Manager to rotate now. The application identity alternates between two users, so the credentials a warm function holds keep working until the rotation after next; when the database refuses a password, the connector of the application reads its secret again, once (docs/infrastructure.md, the two identities of the database). A rotation of the migrations identity, a single user, refuses the console function for the seconds between the change of the password and that of the secret: rotate it while no operation runs.

1. **Rotate the application identity now**, and once more when its old password may have leaked: the second rotation changes the password of the user the first one left.
   - Emulator: `make cloud-rotate SECRET=database-application` (the emulator cannot run the rotation functions of AWS: the console function does their steps, twice, with requests and invoices before and after each)
   - AWS: `aws secretsmanager rotate-secret --secret-id dian-einvoice/prod/database/application`, then `aws secretsmanager describe-secret --secret-id dian-einvoice/prod/database/application --query "[LastRotatedDate,VersionIdsToStages]"` until the new version is `AWSCURRENT`
2. **Rotate the migrations identity now.**
   - Emulator: `make cloud-rotate SECRET=database-migrations` (a new password in its secret, set on its user as the single-user rotation does, and the migrations read with it)
   - AWS: `aws secretsmanager rotate-secret --secret-id dian-einvoice/prod/database/migrations`
3. **Check the functions**: the API answers, the chain moves, and `SecretRereads` counts one read per warm environment, not more.
   - Emulator: `make cloud-deploy STEP=smoke`, then `make cloud-artisan ARGS="db:connections"`
   - AWS: `sh scripts/deploy.sh prod smoke`, and `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"db:connections"' /dev/stdout`
4. **A rotation that fails** is retried by Secrets Manager in its next window; the old credentials keep working meanwhile. Its error is in the log group of its rotation function.

## Afterwards

- Where the secret leaked, and how to keep it from leaking again. A secret in a log means the redactor missed it: add its form to `App\Support\Observability\Redactor` and to the sweep of `make cloud-check`.
