Sample events of AWS Lambda, in the shape AWS documents and the local emulator sends, for the tests of the
functions (tests/Integration/Operations/FunctionsTest.php). The bodies of the SQS messages are filled in by
the test; everything else is as Lambda delivers it. Synthetic data only.

- `sqs-batch.json`: a batch of the chain queue, an event mapping of SQS: one record of a raw SNS delivery
  (the subscriptions of the CDK app), one inside the notification envelope of SNS (a subscription without
  raw delivery), and the template of the others.
- `http-api-v2.json`: a request of API Gateway HTTP API (payload format 2.0) with cookies and the
  X-Forwarded-For API Gateway writes.
- `scheduled.json`: the event EventBridge Scheduler gives the scheduler function: its Input, the command
  line of php artisan for the console runtime of Bref.
