# The notifier fails: `notifier-failures`

| | |
| --- | --- |
| Alarm | `notifier-failures`: a notification in the dead-letter queue of the notifier (1 minute); it also goes to the fallback topic, whose email does not depend on the notifier |
| Objective | none directly: it keeps every other alarm reaching the operators |
| In the emulator | `make cloud-alarm-check` runs it for real: the local receiver refuses a notification, the notifier keeps it in its dead-letter queue, and this alarm reaches the chat once it takes messages again |

## What it means and who it affects

The notifier could not post a change of state of an alarm to the chat webhook after three attempts (or Lambda could not run it after two retries), so the chat missed it: the operators may not know that something else is in `ALARM`. This alarm is the one that also emails the fallback address (`dian-einvoice-prod-alarms-fallback`), so it reaches them without the chat. No customer is affected by it; every customer may be by the alarms it hid.

## Confirm it

1. **What the chat missed**: the alarms of the notifications kept in the dead-letter queue of the notifier, and their states.
   - Emulator: `make cloud-dead-letters QUEUE=notifier`
   - AWS: `aws sqs receive-message --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-notifier-dlq --query QueueUrl --output text)" --visibility-timeout 0 --max-number-of-messages 10 --attribute-names All`
2. **The state of every alarm now**, which counts more than the notifications missed.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-name-prefix dian-einvoice-prod- --state-value ALARM --query "MetricAlarms[].[AlarmName,StateReason]"`
3. **Why the posts failed**: the status the chat answered, or the error of the connection.
   - Emulator: `make cloud-errors FN=notifier MINUTES=60`
   - AWS: `aws logs filter-log-events --log-group-name /aws/lambda/dian-einvoice-prod-notifier --start-time <epoch ms of an hour ago> --filter-pattern '{ $.event = "alarm.notification" && $.outcome = "failure" }'`

## Probable causes

- **The address of the webhook is revoked or wrong**: the chat answers 404 or 403 to every post. The address is a credential the chat issues and can revoke.
- **The chat is down** or limits the rate of posts (429).
- **The secret of the address is unreadable**: `dian-einvoice/prod/alerts/chat-webhook` deleted, or the role of the notifier changed.

## Respond

4. **Attend every alarm in `ALARM` first**, from step 2, with its runbook: they are what the chat missed.
5. **A new address of the webhook**, issued by the chat, put in its secret ([rotate-secrets.md](rotate-secrets.md), the address of the chat webhook); the notifier reads it within 5 minutes.
   - Emulator: `make cloud-rotate SECRET=chat-webhook URL=<address>`
   - AWS: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/alerts/chat-webhook --secret-string '{"url":"<address>"}'`
6. **Empty the dead-letter queue of the notifier** once its notifications were read: they have no queue to go back to, and the alarms of step 2 say their current state.
   - Emulator: `make cloud-dead-letters-purge`
   - AWS: `aws sqs purge-queue --queue-url "$(aws sqs get-queue-url --queue-name dian-einvoice-prod-notifier-dlq --query QueueUrl --output text)"`

## Verify the recovery

7. **A notification reaches the chat**: a state forced on an alarm that is `OK`, and put back. Say in the chat first that it is a test.
   - Emulator: `make cloud-alarms` shows the last messages the chat took; `make cloud-check` forces one
   - AWS: `aws cloudwatch set-alarm-state --alarm-name dian-einvoice-prod-notifier-failures --state-value ALARM --state-reason "A test of the chat after a new address"`, then the same with `--state-value OK`
8. **The alarm is back to `OK`** with its dead-letter queue empty.
   - Emulator: `make cloud-alarms`
   - AWS: `aws cloudwatch describe-alarms --alarm-names dian-einvoice-prod-notifier-failures --query "MetricAlarms[].[StateValue,StateReason]"`

## Afterwards

- The fallback email reached the operators: confirm its subscription is confirmed (SNS asks the address to confirm it once).
- How long the chat was blind, and what it missed.
