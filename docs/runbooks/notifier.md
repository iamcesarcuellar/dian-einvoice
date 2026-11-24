# The notifier fails: `notifier-failures`

**English** · [Español](../es/runbooks/notifier.md)

| | |
| --- | --- |
| Alarm | `notifier-failures`: a notification in the notifier's dead-letter queue (1 minute). This alarm also goes to the fallback topic, whose email does not depend on the notifier. |
| Service objective | none directly: it keeps every other alarm reaching the operators |
| In the emulator | `make cloud-alarm-check` runs it for real. The local webhook receiver refuses a notification, the notifier keeps it in its dead-letter queue, and this alarm reaches the chat once the receiver takes messages again. |

## What it means and who it affects

The notifier could not post an alarm's state change to the chat webhook after three attempts, or Lambda could not run it after two retries. So the chat missed that change, and the operators may not know that something else is in `ALARM`.

This is the one alarm that also publishes to the fallback topic (`dian-einvoice-prod-alarms-fallback`), whose subscription emails the operators, so it reaches them without the chat. It affects no customer directly, but the alarms it hid may affect every customer.

## Confirm it

1. **What the chat missed**: the alarm and the state each notification in the notifier's dead-letter queue carries.
   - Emulator: `make cloud-dead-letters QUEUE=notifier`
   - AWS: `aws sqs receive-message --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-notifier-dlq --query QueueUrl --output text)" --visibility-timeout 0 --max-number-of-messages 10 --attribute-names All`
2. **Every alarm's current state**, which matters more than the missed notifications.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-name-prefix dian-einvoice-prod- --state-value ALARM --query "MetricAlarms[].[AlarmName,StateReason]"`
3. **Why the posts failed**: the status the chat returned, or the connection error.
   - Emulator: `make cloud-errors FN=notifier MINUTES=60`
   - AWS: `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-notifier --start-time <epoch ms of an hour ago> --filter-pattern '{ $.event = "alarm.notification" && $.outcome = "failure" }'`

## Probable causes

- **The webhook address is revoked or wrong**: the chat returns 404 or 403 for every post. The address is a credential the chat issues and can revoke.
- **The chat is down**, or it rate-limits the posts (429).
- **The secret that holds the address is unreadable**: `dian-einvoice/prod/alerts/chat-webhook` was deleted, or the notifier's role changed.

## Respond

4. **Handle every alarm in `ALARM` first**, from step 2, with its runbook: those alarms are what the chat missed.
5. **A new webhook address**: get one from the chat and put it in its secret (see "The chat webhook address" in [rotate-secrets.md](rotate-secrets.md)). The notifier reads it within 5 minutes.
   - Emulator: `make cloud-rotate SECRET=chat-webhook URL=<address>`
   - AWS: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/alerts/chat-webhook --secret-string '{"url":"<address>"}'`
6. **Empty the notifier's dead-letter queue** once you have read its notifications. They have no queue to go back to, and the alarms from step 2 show their current state.
   - Emulator: `make cloud-dead-letters-purge`
   - AWS: `aws sqs purge-queue --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-notifier-dlq --query QueueUrl --output text)"`

## Verify the recovery

7. **A notification reaches the chat**: force a state on an alarm that is `OK`, then put it back. Say in the chat first that it is a test.
   - Emulator: `make cloud-alarms` shows the last messages the chat received; `make cloud-check` forces one
   - AWS: `aws cloudwatch set-alarm-state --alarm-name dian-einvoice-prod-notifier-failures --state-value ALARM --state-reason "A test of the chat after a new address"`, then the same with `--state-value OK`
8. **The alarm is back to `OK`**, with its dead-letter queue empty.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-notifier-failures --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- Whether the fallback email reached the operators: check that its subscription is confirmed (SNS asks the address to confirm it once).
- How long the chat was blind, and what it missed.
