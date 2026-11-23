#!/bin/sh
# make cloud-drill: the incident drill of the emulated cloud (make cloud-up first), apart from make cloud-check, on a
# temporary company of its own that is deleted at the end, whatever happens:
#  1. baseline: invoices issued, accepted and delivered; the dead-letter queues empty and the alarm not in ALARM;
#  2. incident: the DIAN goes down (the failure control of the simulator answers HTTP 500 to every call, for every NIT)
#     and more invoices are issued through the edge with an API key, as an integration issues them;
#  3. detection: their transmissions retry and give up, their messages reach the dead-letter queue of the chain, the
#     emulator turns the alarm chain-dead-letters to ALARM, and its notification reaches the local receiver of the chat
#     naming its runbook;
#  4. response: the commands the runbooks give for the emulator (docs/runbooks/dead-letters.md, dian-errors.md), as an
#     operator runs them, each printed before it runs: the alarms, the dead letters, the journey of one invoice with the
#     saved query, the chain, the probe of the DIAN; the drill then restores the DIAN (in AWS it comes back by itself),
#     the probe confirms it, and the native redrive of SQS moves the dead letters back;
#  5. verification: every invoice of the incident accepted and delivered exactly once, the numbering without gaps, both
#     dead-letter queues empty, and the alarm back to OK, which the chat hears too; and the journey of one of them, with
#     the saved query of make cloud-follow, one story under its correlation id: the failure, the dead letter, the step
#     that came back from it and the recovery;
#  6. the timeline: the time to detect, to notify and to recover, figures of the emulator.
# The report of one run, as a blameless review of the incident, is docs/runbooks/drill-report.md.
set -eu

baseline="${1:-3}"
incident="${2:-5}"
compose="docker compose --env-file backend/.env --profile cloud"
company=""
verified=0
status=0
started=$(date +%s)

tools() {
  $compose run --rm --no-deps -T functions-deploy "$@"
}

console() {
  tools node scripts/console.mjs "$@"
}

# A command of the runbook, as an operator runs it, printed first.
runbook() {
  echo "   \$ make $*"
  make --no-print-directory "$@"
}

ms() {
  echo "$(date +%s)000"
}

value() {
  echo "$1" | sed -n "s/^$2: //p" | head -1
}

pair() {
  echo "$1" | sed -n "s/^$2=//p" | tail -1
}

elapsed() {
  echo "   ($(($(date +%s) - started)) s since the start)"
}

# shellcheck disable=SC2317
cleanup() {
  if [ "$verified" -eq 0 ]; then
    console cloud:drill cleanup "$company" >/dev/null 2>&1 || true
  fi
}

trap cleanup EXIT

echo "1. Baseline: $baseline invoices, and a healthy platform"
tools node scripts/drill.mjs healthy || status=1
output=$(console cloud:drill start --invoices="$baseline" --timeout=180) || status=1
echo "$output"
company=$(value "$output" company)

if [ -z "$company" ]; then
  echo "   FAIL  cloud:drill start printed no company."
  exit 1
fi

elapsed

echo "2. Incident: the DIAN goes down, and $incident more invoices are issued through the edge"
since=$(ms)
output=$(console cloud:drill incident "$company" --invoices="$incident") || status=1
echo "$output"
incident_at=$(value "$output" incident)
correlation=$(value "$output" correlation)
elapsed

echo "3. Detection: the retries, the dead-letter queue, the alarm and its notification"
detected=$(tools node scripts/drill.mjs detect "$since" "$incident") || status=1
echo "$detected" | grep -v '=' || true
progress=$(console cloud:drill progress "$company") || status=1
echo "   Where the incident's invoices are: $progress"
first_failure=$(echo "$progress" | sed -n 's/.*"first_failure_at":"\([^"]*\)".*/\1/p')
elapsed

echo "4. Response: the runbook docs/runbooks/dead-letters.md, with its commands for the emulator"
runbook cloud-alarms || status=1
runbook cloud-dead-letters QUEUE=chain || status=1
runbook cloud-follow ID="$correlation" HOURS=1 || status=1
runbook cloud-artisan ARGS="chain:status" || status=1
echo "   The probe in docs/runbooks/dian-errors.md says the DIAN is down (it exits 1):"
if runbook cloud-artisan ARGS="dian:probe"; then
  status=1
fi
console cloud:drill restore
restored=$(ms)
runbook cloud-artisan ARGS="dian:probe" || status=1
runbook cloud-redrive QUEUE=chain || status=1
redriven=$(ms)
elapsed

echo "5. Verification: every invoice accepted and delivered once, no gap, the dead-letter queues empty, the alarm OK"
output=$(console cloud:drill verify "$company" --timeout=240) || status=1
verified=1
echo "$output"
recovered_at=$(value "$output" recovered)
back=$(tools node scripts/drill.mjs recovered "$redriven") || status=1
echo "$back" | grep -v '=' || true
echo "   After the recovery, the journey of one of the incident's invoices, by its response's correlation id:"
runbook cloud-follow ID="$correlation" HOURS=1 || status=1
tools node scripts/drill.mjs story "$correlation" "$since" || status=1
elapsed

echo "6. Timeline"
tools node scripts/drill.mjs timeline "incident=$incident_at" "first_failure=$first_failure" "dead_letters=$(pair "$detected" dead_letters_at)" "alarm=$(pair "$detected" alarm_at)" "notified=$(pair "$detected" notified_at)" "restored=$restored" "redriven=$redriven" "recovered=$recovered_at" "ok=$(pair "$back" ok_at)"

if [ "$status" -ne 0 ]; then
  echo "make cloud-drill FAILED."
else
  echo "make cloud-drill passed, in $(($(date +%s) - started)) s."
fi

exit "$status"
