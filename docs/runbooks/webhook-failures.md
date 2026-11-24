# Webhooks that fail: `webhook-failures`

**English** · [Español](../es/runbooks/webhook-failures.md)

| | |
| --- | --- |
| Alarm | `webhook-failures`: 20 or more failed webhook deliveries in 15 minutes (the metric filter `WebhookDeliveriesFailed`) |
| Service objective | none of its own: webhooks are delivered at least once and in no particular order |
| In the emulator | The metric filter and its alarm run for real. The simulator's webhook receiver fails on demand (`make api-check` and `make cloud-alarm-check` use its error mode). |

## What it means and who it affects

The webhooks do not get through: the companies' endpoints do not take them, or the platform's outbound path to them fails. Each delivery is tried once, then five more times, 2, 5, 15, 45 and 120 s apart. After that, the delivery fails and is kept. A subscription that fails 20 deliveries in a row is switched off (`disabled`).

Those companies' integrations stop hearing about their invoices (issued, accepted, rejected, paid). The invoices themselves are not affected. One failing subscription is its company's problem; many at once are the platform's.

## Confirm it

1. **Which subscriptions fail**, by company and by their URL's host (never its path), with their failures in a row and their last response.
   - Emulator: `make cloud-artisan ARGS="webhooks:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"webhooks:status --hours=1"' /dev/stdout`
2. **What the attempts logged**: each response's status, or the connection error.
   - Emulator: `make cloud-errors FN=webhooks MINUTES=30`
   - AWS: `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-webhooks --start-time <epoch ms of 30 minutes ago> --filter-pattern '{ $.event = "webhook.delivery" && $.outcome = "failure" }'`

## Probable causes

- **One endpoint is down, slow or returns 5xx**: one subscription, one company, in `webhooks:status`.
- **One endpoint refuses** (4xx, a wrong secret on their side, a changed URL): one subscription, with the same status on every attempt.
- **Many endpoints at once**: the platform's outbound path (prod: the NAT gateway), or DNS. The DIAN and SES fail too ([dian-errors.md](dian-errors.md)).
- **An endpoint that is not https on port 443** is refused before each attempt in prod (docs/limitations.md).
- **A company allow list** without the platform's fixed addresses (`/dian-einvoice/prod/network/egress-addresses`).

## Respond

3. **One company**: tell it which subscription fails, since when, and what its endpoint returned. There is nothing to change on the platform. Once its endpoint works, the company goes to Developers in the web app and:
   - sends a test from the subscription's menu (Send test event);
   - switches the subscription back on in its form (Active), if it was disabled;
   - redelivers what it missed from the subscription's Deliveries (Redeliver, on each failed delivery).
4. **Many at once**: check the outbound path ([dian-errors.md](dian-errors.md), step 6). The deliveries that failed meanwhile stay failed: tell the companies to redeliver them, or to wait for the events that follow.
5. **Messages in the webhooks queue that failed on every receive** are in that queue's dead-letter queue. They are jobs that threw, not failed deliveries: follow [dead-letters.md](dead-letters.md) with `QUEUE=webhooks`.

## Verify the recovery

6. **The failures stop**: no new failed deliveries, and the subscriptions are back to `active`.
   - Emulator: `make cloud-artisan ARGS="webhooks:status"`
   - AWS: `aws lambda invoke --function-name dian-einvoice-prod-console --cli-binary-format raw-in-base64-out --payload '"webhooks:status --hours=1"' /dev/stdout`
7. **The alarm is back to `OK`** once fewer than 20 deliveries fail in 15 minutes.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-webhook-failures --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- A company whose endpoint fails often: a word on idempotency and quick responses (the platform waits 5 s per attempt).
- Many at once: with one NAT gateway, the platform's outbound path is a single point of failure (`natGateways: 2` adds a second one).
