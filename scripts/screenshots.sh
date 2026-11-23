#!/bin/sh
# make screenshots: the screenshots and the GIFs of the README, made by script only, in English (docs/assets, for
# README.md) and in Spanish (docs/assets/es, the same names, for README.es.md), against the local stack (make up, which
# the target runs first) and its synthetic demo data, reloaded here as make seed does, in the language of each run:
# local data is lost. Every clock of the story is set to Thursday, November 19, 2026, 3 p.m. in Medellín, the day the
# demo data was written for, so two runs give the same images whatever day they run: the backend and the DIAN
# simulator run with the story clock of scripts/screenshots/compose.yaml while the script lasts, and the browser with
# the same moment. The browser (Playwright, compose service e2e) captures them (e2e/screenshots/screens.spec.ts), set
# to British English or to Colombian Spanish (LANG=es_CO.UTF-8) with the web app in that language, and
# e2e/screenshots/encode.mjs writes them, each screenshot under 400 KB and each GIF under 5 MB, or the run fails. When it
# ends, the containers of the backend and of the simulator are recreated with the clock of the machine; the demo data
# stays at the story day, in the language of the last run (make seed reloads it as of today).
#
#   sh scripts/screenshots.sh                          both languages, English first
#   SCREENSHOTS_LANGUAGES=es sh scripts/screenshots.sh  one of them
set -eu

compose="docker compose --env-file backend/.env"
clock="$compose -f compose.yaml -f scripts/screenshots/compose.yaml"
story='2026-11-19T15:00:00-05:00'
log=$(mktemp)

# The offset of the story clock from the clock of the machine, in seconds, and sessions long enough for a cookie set
# at a moment of the story to be kept by the browser, which expires cookies by its own clock.
offset=$($compose exec -T api php -r 'echo strtotime($argv[1]) - time();' "$story")
lifetime=$(( (offset < 0 ? -offset : 0) / 60 + 180 ))
export SCREENSHOTS_CLOCK_OFFSET="$offset" SCREENSHOTS_SESSION_LIFETIME="$lifetime"

restore() {
  status=$?
  rm -f "$log"
  echo "Back to the machine's clock: recreating the API, the workers, the relay and the simulator."
  $compose up -d --force-recreate --wait api simulator >/dev/null 2>&1 || status=1
  $compose up -d --force-recreate worker relay >/dev/null 2>&1 || status=1
  exit "$status"
}
trap restore EXIT
trap 'exit 130' INT TERM

echo "Story clock: $story ($offset s from this machine's clock)."
$compose stop worker relay >/dev/null 2>&1
$clock up -d --force-recreate --wait api simulator >/dev/null 2>&1

# One language: the demo data in it (its seeder), then the browser in it, into its folder of docs/assets.
record() {
  language=$1
  seeder=$2
  assets=$3

  echo "Reloading the synthetic demo data as of the story day, in $language."
  $compose stop worker relay >/dev/null 2>&1

  if ! $clock exec -T api php artisan migrate:fresh --seed --seeder="$seeder" --force --no-interaction >"$log" 2>&1; then
    # The seeder prints the synthetic demo API key; the failure is in the last lines.
    tail -n 40 "$log" | grep -v 'dei_' >&2
    exit 1
  fi

  $clock up -d --force-recreate --wait worker relay >/dev/null 2>&1

  echo "Recording the screenshots and the GIFs in $language, into $assets."
  mkdir -p "$assets"
  $compose --profile e2e run --rm --no-deps -T \
    -e SCREENSHOTS_CLOCK_OFFSET \
    -e SCREENSHOTS_LANGUAGE="$language" \
    -e SCREENSHOTS_RAW="/tmp/screenshots/$language" \
    -v "$(pwd)/$assets:/assets" \
    e2e sh -c "npm ci --no-audit --no-fund && npx playwright test --config screenshots/playwright.config.ts && node screenshots/encode.mjs /tmp/screenshots/$language /assets"
}

for language in ${SCREENSHOTS_LANGUAGES:-en es}; do
  case "$language" in
    en) record en DatabaseSeeder docs/assets ;;
    es) record es SpanishDemoSeeder docs/assets/es ;;
    *) echo "SCREENSHOTS_LANGUAGES takes en and es, not $language." >&2; exit 2 ;;
  esac
done
