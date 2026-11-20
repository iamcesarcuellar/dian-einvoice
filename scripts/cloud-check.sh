#!/bin/sh
# make cloud-check: the end-to-end check of the emulated cloud (make cloud-up first), where the application
# runs as Lambda functions in the local AWS emulator and nothing of it runs all the time. In order:
#  1. no process of the application runs all the time: only the emulator, the database, the DIAN simulator,
#     the web app and the proxy in front of API Gateway are up;
#  2. every function, mapping, route and schedule of the stage local exists for real in the emulator;
#  3. the cold start and the warm answers of the API through API Gateway (figures of the emulator);
#  4. the fast path: CLOUD_INVOICES invoices through the chain, each step published by its writer as it
#     commits, with the time from issue to delivery; the session cookie with CSRF and the downloads of an
#     invoice through API Gateway;
#  5. a partial batch response: an unreadable message among valid ones in one batch;
#  6. chaos: more invoices, one outbox event left unpublished, and the containers of the functions killed
#     mid-process;
#  7. everything ends accepted, delivered and notified once, the relay function published the event left
#     behind, the scheduler ran, and the AWS services hold what they should.
# Every step runs in the console function; the temporary company is deleted at the end, whatever happens.
set -eu

invoices="${1:-30}"
chaos_invoices="${CLOUD_CHAOS_INVOICES:-10}"
compose="docker compose --env-file backend/.env --profile cloud"
company=""
finished=0
status=0
started=$(date +%s)

tools() {
  $compose run --rm --no-deps -T functions-deploy "$@"
}

console() {
  tools node scripts/console.mjs "$@"
}

step() {
  "$@" || status=1
}

elapsed() {
  echo "   ($(($(date +%s) - started)) s since the start)"
}

cleanup() {
  if [ "$finished" -eq 0 ] && [ -n "$company" ]; then
    console cloud:check cleanup "$company" >/dev/null 2>&1 || true
  fi
}

trap cleanup EXIT

echo "1. No process of the application runs all the time"
running=$($compose ps --status running --services | sort | tr '\n' ' ')
permanent=""

for service in api worker relay; do
  case " $running" in
    *" $service "*) permanent="$permanent $service" ;;
  esac
done

if [ -z "$permanent" ] && [ -z "$(docker ps -q --filter ancestor=dian-einvoice-api:latest)" ]; then
  echo "   PASS  The services up are $running: no container of the application runs all the time; the functions run in containers the emulator starts for each environment."
else
  echo "   FAIL  Processes of the application run all the time:${permanent:- containers of the image of the local API}."
  status=1
fi

# The emulator runs the functions as an unprivileged user that can write to /tmp only, on a writable file
# system; AWS makes everything but /tmp read-only. Here the image starts on a read-only file system as a cold
# start of Bref does (storage directories and the configuration cached in /tmp) and boots the application.
# shellcheck disable=SC2016
if docker run --rm --read-only --tmpfs /tmp:rw,exec,size=256m --user 993:990 --network none -e LAMBDA_TASK_ROOT=/var/task -e LOG_CHANNEL=stderr -e BREF_LARAVEL_CACHE_CONFIG=1 \
  --entrypoint sh dian-einvoice-lambda:latest -c 'php -r "require \"/var/task/vendor/autoload.php\"; Bref\Bref::events()->beforeStartup();" && test -f /tmp/storage/bootstrap/cache/config.php && APP_CONFIG_CACHE=/tmp/storage/bootstrap/cache/config.php php /var/task/artisan about --only=environment,cache' >/dev/null 2>&1; then
  echo "   PASS  The image of the functions starts on a read-only file system but /tmp, as an unprivileged user: storage and the cached configuration in /tmp."
else
  echo "   FAIL  The image of the functions does not start on a read-only file system but /tmp."
  status=1
fi

echo "2. The functions and their triggers exist for real in the emulator"
step tools node scripts/cloud-check.mjs resources

echo "3. Cold start and warm answers of the API (figures of the emulator, not of AWS)"
api=$(docker ps -q --filter label=dian-einvoice=floci --filter name=^dian-einvoice-dian-einvoice-dian-einvoice-local-api-)
if [ -n "$api" ]; then
  # shellcheck disable=SC2086
  docker rm --force $api >/dev/null
fi
step tools node scripts/cloud-check.mjs cold
step tools node scripts/cloud-check.mjs warm
elapsed

echo "4. The fast path: $invoices invoices"
output=$(console cloud:check start --invoices="$invoices") || status=1
echo "$output"
company=$(echo "$output" | sed -n 's/^company: //p' | tail -1)

if [ -z "$company" ]; then
  echo "   FAIL  cloud:check start printed no company."
  exit 1
fi

step console cloud:check fast "$company" --timeout=300
elapsed

echo "5. A partial batch response"
step console cloud:check partial
elapsed

echo "6. Chaos: $chaos_invoices more invoices, an outbox event left behind, the functions killed mid-process"
step console cloud:check chaos "$company" --invoices="$chaos_invoices" --until-accepted=3
killed=$(sh scripts/cloud/containers.sh kill-functions)
echo "   Killed $killed containers of functions of this project; the messages they held come back after the visibility timeout of their queue."
elapsed

echo "7. Everything ends once"
step console cloud:check finish "$company" --timeout=300
finished=1
elapsed

if [ "$status" -ne 0 ]; then
  echo "make cloud-check FAILED."
fi

exit "$status"
