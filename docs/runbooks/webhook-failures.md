# Webhooks that fail: `webhook-failures`

| | |
| --- | --- |
| Alarm | `webhook-failures`: 20 or more failed webhook deliveries in 15 minutes (the metric filter `WebhookDeliveriesFailed`) |
| Objective | none of its own: webhooks are delivered at least once and in no particular order |
| In the emulator | the metric filter and its alarm run for real; the local receiver of the DIAN simulator fails on demand (`make api-check` and `make cloud-alarm-check` use its error mode) |

## What it means and who it affects

The endpoints of the companies, or the way out of the platform to them, do not take the webhooks: each delivery is tried a first time and five more, 2, 5, 15, 45 and 120 s apart, then it fails and is kept; a subscription that fails 20 deliveries in a row is switched off (`disabled`). The integrations of those companies stop hearing about their invoices (issued, accepted, rejected, paid); the invoices themselves are not affected. One subscription failing is the problem of its company; many at once, of the platform.

## Confirm it

1. **Which subscriptions fail**, by company and by the host of their URL (never its path), their failures in a row, their last answer.
   - Emulator: `make cloud-artisan ARGS="webhooks:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"webhooks:status --hours=1"' /dev/stdout`
2. **What the attempts wrote**: the status of each answer, or the error of the connection.
   - Emulator: `make cloud-errors FN=webhooks MINUTES=30`
   - AWS: `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-webhooks --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "webhook.delivery" && $.outcome = "failure" }'`

## Probable causes

- **One endpoint is down, slow or answers 5xx**: one subscription, one company, in `webhooks:status`.
- **One endpoint refuses** (4xx, a wrong secret on their side, a changed URL): one subscription, every attempt the same status.
- **Many endpoints at once**: the way out of the platform (prod: the NAT gateway), or the DNS; the DIAN and SES fail too ([dian-errors.md](dian-errors.md)).
- **An endpoint that is not https on port 443** in prod is refused before each attempt (docs/limitations.md).
- **An allow list of the company** without the fixed addresses of the platform (`/dian-einvoice/prod/network/egress-addresses`).

## Respond

3. **One company**: tell it which subscription fails, since when, and what its endpoint answered; nothing to change on the platform. Once its endpoint works, the company, in Developers of the web app, sends a test from the menu of the subscription (Send test event), switches it on again in its form (Active) if it was disabled, and redelivers what it missed from its Deliveries (each failed delivery, Redeliver).
4. **Many at once**: the way out ([dian-errors.md](dian-errors.md), step 6); the deliveries that failed meanwhile stay failed: tell the companies to redeliver them, or wait for the events that follow.
5. **Messages of the queue webhooks that failed on every receive** (not deliveries that failed, but jobs that threw) are in its dead-letter queue: [dead-letters.md](dead-letters.md) with `QUEUE=webhooks`.

## Verify the recovery

6. **The failures stop**: no new failed delivery, the subscriptions back to `active`.
   - Emulator: `make cloud-artisan ARGS="webhooks:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"webhooks:status --hours=1"' /dev/stdout`
7. **The alarm is back to `OK`** once fewer than 20 fail in 15 minutes.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-webhook-failures --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- A company whose endpoint fails often: a word on idempotency and quick answers (the platform waits 5 s per attempt).
- Many at once: the way out of the platform is a single point of failure with one NAT gateway (`natGateways: 2`).
