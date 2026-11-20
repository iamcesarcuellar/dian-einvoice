#!/bin/sh
# make cloud-check: the end-to-end check of the emulated cloud (make cloud-up first), where the application
# runs as Lambda functions in the local AWS emulator and nothing of it runs all the time. In order:
#  1. no process of the application runs all the time: only the emulator, the database, the DIAN simulator,
#     the web app and the proxy in front of API Gateway are up; the image of the functions starts on a
#     read-only file system and carries the bundle of the RDS certificate authorities;
#  2. every function, mapping, route, authorizer and schedule of the stage local exists for real in the emulator,
#     and each function connects to the database as its identity;
#  3. the edge: the distribution holds the behaviours of its template; API Gateway refuses a request without the
#     secret of the edge or with a wrong one, before the application; through the edge the API answers with the
#     headers of the application, the web app comes from its bucket with every security header of
#     backend/resources/http/security-headers.json, a deep link answers index.html (the cases of the CloudFront
#     Function), and the errors of the API stay problems of the API;
#  4. the two identities of the database: the migrations run through the console function with the migrations
#     identity, which provisions the application identity; the application identity cannot change the schema;
#  5. the cold start and the warm answers of the API through the edge (figures of the emulator);
#  6. the fast path: CLOUD_INVOICES invoices through the chain, each step published by its writer as it
#     commits, with the time from issue to delivery; through the edge, on the origin of the web app, the session
#     cookie with CSRF, the downloads of an invoice, a 403 of the API, and an address the client writes, which
#     the rate limits do not count by;
#  7. a partial batch response: an unreadable message among valid ones in one batch;
#  8. chaos: more invoices, one outbox event left unpublished, and the containers of the functions killed
#     mid-process;
#  9. everything ends accepted, delivered and notified once, the relay function published the event left
#     behind, the scheduler ran, and the AWS services hold what they should;
# 10. a simulated rotation of the application identity, twice, with the functions warm: requests and
#     invoices work before and after each, and the functions whose password went read their secret again;
# 11. the observability: every line the functions wrote during the check is JSON in the schema of the log lines;
#     an invoice issued through the edge in step 6 is followed with the saved query by the X-Correlation-Id its
#     client got, through api, chain and webhooks; no log holds a secret or personal data; a metric filter produced
#     its metric; and a state forced on an alarm reaches the chat through the notifier (the real evaluation of an
#     alarm, which takes a minute or more, is make cloud-alarm-check).
# Every step runs in the console function; the temporary companies are deleted at the end, whatever happens.
set -eu

invoices="${1:-30}"
chaos_invoices="${CLOUD_CHAOS_INVOICES:-10}"
compose="docker compose --env-file backend/.env --profile cloud"
company=""
finished=0
status=0
started=$(date +%s)
# The lines the functions write from now on are the lines of the check (step 11).
since_ms=$((started * 1000))
correlation=""

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

for service in api worker relay web; do
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

# The certificate authorities of Amazon RDS the functions verify the managed database of prod with, and encrypt
# with here: the bundle in the image parses, and holds the authority that signs the instances of us-east-1.
# shellcheck disable=SC2016
bundle=$(docker run --rm --network none --entrypoint php dian-einvoice-lambda:latest -r '$pem = (string) file_get_contents("/opt/rds/global-bundle.pem"); preg_match_all("/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s", $pem, $m); $names = array_map(fn ($c) => openssl_x509_parse($c)["subject"]["CN"] ?? "", $m[0]); echo count($names), " ", in_array("Amazon RDS us-east-1 Root CA RSA2048 G1", $names, true) ? "yes" : "no";' 2>/dev/null || true)
case "$bundle" in
  *" yes")
    echo "   PASS  The image of the functions carries the bundle of the RDS certificate authorities (${bundle% yes} of them, Amazon RDS us-east-1 Root CA RSA2048 G1 included), with which prod verifies the managed database."
    ;;
  *)
    echo "   FAIL  The image of the functions has no usable bundle of the RDS certificate authorities (${bundle:-none})."
    status=1
    ;;
esac

echo "2. The functions and their triggers exist for real in the emulator"
step tools node scripts/cloud-check.mjs resources

echo "3. The edge: the distribution, the origin verifier and the web app"
step tools node scripts/edge.mjs check
step tools node scripts/cloud-check.mjs edge

echo "4. The two identities of the database"
step console migrate --force --no-interaction
step console db:application-identity
step console cloud:check identities
elapsed

echo "5. Cold start and warm answers of the API through the edge (figures of the emulator, not of AWS)"
api=$(docker ps -q --filter label=dian-einvoice=floci --filter name=^dian-einvoice-dian-einvoice-dian-einvoice-local-api-)
if [ -n "$api" ]; then
  # shellcheck disable=SC2086
  docker rm --force $api >/dev/null
fi
step tools node scripts/cloud-check.mjs cold
step tools node scripts/cloud-check.mjs warm
elapsed

echo "6. The fast path: $invoices invoices"
output=$(console cloud:check start --invoices="$invoices") || status=1
echo "$output"
company=$(echo "$output" | sed -n 's/^company: //p' | tail -1)

if [ -z "$company" ]; then
  echo "   FAIL  cloud:check start printed no company."
  exit 1
fi

step console cloud:check fast "$company" --timeout=300
traced=$(console cloud:check traced "$company" --timeout=300) || status=1
echo "$traced"
correlation=$(echo "$traced" | sed -n 's/^correlation: //p' | tail -1)
elapsed

echo "7. A partial batch response"
step console cloud:check partial
elapsed

echo "8. Chaos: $chaos_invoices more invoices, an outbox event left behind, the functions killed mid-process"
step console cloud:check chaos "$company" --invoices="$chaos_invoices" --until-accepted=3
killed=$(sh scripts/cloud/containers.sh kill-functions)
echo "   Killed $killed containers of functions of this project; the messages they held come back after the visibility timeout of their queue."
elapsed

echo "9. Everything ends once"
step console cloud:check finish "$company" --timeout=300
finished=1
elapsed

echo "10. A rotation of the application identity, twice, with the functions warm"
step console cloud:check rotation --timeout=300
elapsed

echo "11. The observability: the logs of the functions, a metric and the path of an alarm"
if [ -n "$correlation" ]; then
  step tools node scripts/observability.mjs check "$since_ms" "$correlation"
else
  echo "   FAIL  Step 6 printed no correlation id to follow."
  status=1
fi
elapsed

if [ "$status" -ne 0 ]; then
  echo "make cloud-check FAILED."
else
  echo "make cloud-check passed."
fi

exit "$status"
