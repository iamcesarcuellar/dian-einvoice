# Rotate a secret off schedule

**English** · [Español](../es/runbooks/rotate-secrets.md)

A procedure without an alarm. Use it when a secret may have leaked: in a log, a screenshot, a lost laptop, a person who left. Also use it to rotate a secret before its schedule for any other reason.

Each secret has its own procedure, because each one reaches its users in its own way. Rotate one secret at a time, and say in the chat which one and when.

| Secret | Who uses it | Rotated on schedule | Off schedule |
| --- | --- | --- | --- |
| `dian-einvoice/prod/edge/origin-verify` | The distribution sends it to API Gateway, and the origin verifier checks it | No: by this procedure, whenever needed | [below](#the-edge-secret) |
| `dian-einvoice/prod/alerts/chat-webhook` | The notifier posts the alarms to its address | No: the chat issues it | [below](#the-chat-webhook-address) |
| `dian-einvoice/prod/database/application` | Every PHP function except for the console function | Every 30 days, alternating users | [below](#the-database-credentials) |
| `dian-einvoice/prod/database/migrations` | The console function and the rotation functions | Every 30 days, single user | [below](#the-database-credentials) |
| `dian-einvoice/prod/app` (`APP_KEY`) | Every PHP function: it encrypts stored values | No | Not supported: every encrypted value would need re-encrypting first (docs/limitations.md) |

## The edge secret

The origin verifier accepts both the current and the previous version of the secret. So a rotation causes no outage, as long as a second rotation does not drop a value that an edge location still sends. See docs/infrastructure.md for the origin verifier.

1. **Set a new value as the current version.** The value it replaces stays valid as the previous version. Note the version id in the response.
   - Emulator: `make cloud-rotate SECRET=edge` does steps 1 to 4: the new value, the edge stack deployed with it, and the smoke test.
   - AWS: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/edge/origin-verify --secret-string "$(openssl rand -hex 32)"`
2. **Wait 30 s**, the time the verifier keeps the values. A value it does not hold makes it read the secret again at once.
3. **Deploy the edge with that version.** CloudFormation resolves the new value, and CloudFront sends it as each edge location takes the new configuration. Meanwhile, the other edge locations send the previous value, which is still valid.
   - Emulator: part of `make cloud-rotate SECRET=edge`; on its own, `make cloud-deploy STEP=edge`
   - AWS: `ORIGIN_VERIFY_VERSION=<version id> sh scripts/deploy.sh prod edge` with the account's variables (docs/deployment.md), or the deployment workflow.
4. **Wait until the distribution is deployed**, plus 5 minutes for the authorizer's cache, before any other rotation. Then check that the edge responds and that API Gateway refuses requests that skip it.
   - Emulator: part of `make cloud-rotate SECRET=edge`; on its own, `make cloud-deploy STEP=smoke`
   - AWS: `aws cloudfront wait distribution-deployed --id "$(aws ssm get-parameter --name /dian-einvoice/prod/edge/distribution-id --query Parameter.Value --output text)"`, then `sh scripts/deploy.sh prod smoke`
5. **If the old value leaked**, rotate once more after step 4. The second rotation drops it from the previous version too.

## The chat webhook address

The address is a credential: whoever holds it can post to the operators' chat.

1. **Ask the chat for a new address**, and revoke the old one there.
2. **Put it in the secret.** The notifier reads it within 5 minutes, and never logs it.
   - Emulator: `make cloud-rotate SECRET=chat-webhook URL=<address>`
   - AWS: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/alerts/chat-webhook --secret-string '{"url":"<address>"}'`
3. **Check that a notification arrives** after 5 minutes: [notifier.md](notifier.md), step 7.

## The database credentials

The AWS rotation functions rotate both identities every 30 days. To rotate off schedule, ask Secrets Manager to rotate now.

- **The application identity** alternates between two users. So the credentials a warm function holds keep working until the rotation after next. When the database refuses a password, the application's connector reads its secret again, once. See docs/infrastructure.md for the two database identities.
- **The migrations identity** has a single user. Its rotation refuses the console function for the seconds between the password change and the secret change, so rotate it while no operation runs.

1. **Rotate the application identity now**, and once more if its old password may have leaked. The second rotation changes the password of the user the first one left.
   - Emulator: `make cloud-rotate SECRET=database-application` (the emulator cannot run the AWS rotation functions, so the console function does their steps, twice, with requests and invoices before and after each)
   - AWS: `aws secretsmanager rotate-secret --secret-id dian-einvoice/prod/database/application`, then `aws secretsmanager describe-secret --secret-id dian-einvoice/prod/database/application --query "[LastRotatedDate,VersionIdsToStages]"` until the new version is `AWSCURRENT`
2. **Rotate the migrations identity now.**
   - Emulator: `make cloud-rotate SECRET=database-migrations` (it puts a new password in the secret, sets it on the user as the single-user rotation does, and reads the migration status with it)
   - AWS: `aws secretsmanager rotate-secret --secret-id dian-einvoice/prod/database/migrations`
3. **Check the functions**: the API responds, the chain moves, and `SecretRereads` counts one reread per warm environment, no more.
   - Emulator: `make cloud-deploy STEP=smoke`, then `make cloud-artisan ARGS="db:connections"`
   - AWS: `sh scripts/deploy.sh prod smoke`, and `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"db:connections"' /dev/stdout`
4. **A rotation that fails** is retried by Secrets Manager in its next window, and the old credentials keep working meanwhile. Its error is in its rotation function's log group.

## Afterwards

- Where the secret leaked, and how to keep it from leaking again. A secret in a log means the redactor missed it: add its shape to `App\Support\Observability\Redactor` and to the `make cloud-check` sweep.
