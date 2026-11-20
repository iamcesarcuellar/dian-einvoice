#!/bin/sh
# make cloud-check: the end-to-end check of the emulated cloud (make cloud-up first). On a temporary company of
# its own it issues the invoices and, once a few are accepted, kills the consumers of both SQS queues while the
# chain runs and starts them again two seconds later. It then waits for every invoice to be accepted and
# delivered, checks the topic, the queues, the bucket, SES, the tables, KMS and Secrets Manager, sends a message
# no handler can read to the dead-letter queue, and deletes the company, whatever happens.
set -eu

invoices="${1:-30}"
compose="docker compose --env-file backend/.env --profile cloud"
company=""
finished=0

cleanup() {
  if [ "$finished" -eq 0 ] && [ -n "$company" ]; then
    $compose exec -T cloud-api php artisan cloud:check cleanup "$company" >/dev/null 2>&1 || true
  fi
}

trap cleanup EXIT

# Prints only the company id on stdout; progress goes to stderr.
company=$($compose exec -T cloud-api php artisan cloud:check start --invoices="$invoices")

echo "Killing the consumers of the chain and webhooks queues mid-process..."
$compose kill cloud-chain cloud-webhooks
sleep 2
echo "Starting them again; the messages they held come back after the visibility timeout of their queue."
$compose start cloud-chain cloud-webhooks

status=0
$compose exec -T cloud-api php artisan cloud:check finish "$company" --timeout=300 || status=$?
finished=1

exit "$status"
