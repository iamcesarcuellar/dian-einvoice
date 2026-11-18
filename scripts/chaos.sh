#!/bin/sh
# make chaos: the end-to-end check of the asynchronous DIAN chain. On a temporary company of its own it
# issues the invoices; while the chain processes them it kills both queue workers and the outbox relay,
# starts them again two seconds later and then restarts the DIAN simulator. It waits for every invoice
# to reach a final state, checks the result and deletes the company, whatever happens.
set -eu

invoices="${1:-40}"
compose="docker compose"
company=""
finished=0

cleanup() {
  if [ "$finished" -eq 0 ] && [ -n "$company" ]; then
    $compose exec -T api php artisan dian:chaos cleanup "$company" >/dev/null 2>&1 || true
  fi
}

trap cleanup EXIT

# Prints only the company id on stdout; progress goes to stderr.
company=$($compose exec -T api php artisan dian:chaos start --invoices="$invoices")

echo "Killing both queue workers and the outbox relay..."
$compose kill worker relay
sleep 2
echo "Starting them again..."
$compose start worker relay
echo "Restarting the DIAN simulator..."
$compose restart simulator

status=0
$compose exec -T api php artisan dian:chaos finish "$company" --timeout=120 || status=$?
finished=1

exit "$status"
