#!/bin/sh
# make screenshots: the screenshots and the GIFs of the README (docs/assets), made by script only, against the local
# stack (make up, which the target runs first) and its synthetic demo data, reloaded here as make seed does: local data
# is lost. Every clock of the story is set to Thursday, November 19, 2026, 3 p.m. in Medellín, the day the demo data
# was written for, so two runs give the same images whatever day they run: the backend and the DIAN simulator run
# with the story clock of scripts/screenshots/compose.yaml while the script lasts, and the browser with the same
# moment. The browser (Playwright, compose service e2e) captures them (e2e/screenshots/screens.spec.ts) and
# e2e/screenshots/encode.mjs writes them, each screenshot under 400 KB and each GIF under 5 MB, or the run fails.
# When it ends, the containers of the backend and of the simulator are recreated with the clock of the machine; the
# demo data stays at the story day (make seed reloads it as of today).
#
#   sh scripts/screenshots.sh
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
  echo "Back to the clock of the machine: recreating the API, the workers, the relay and the simulator."
  $compose up -d --force-recreate --wait api simulator >/dev/null 2>&1 || status=1
  $compose up -d --force-recreate worker relay >/dev/null 2>&1 || status=1
  exit "$status"
}
trap restore EXIT
trap 'exit 130' INT TERM

echo "Story clock: $story ($offset s from the clock of this machine)."
$compose stop worker relay >/dev/null 2>&1
$clock up -d --force-recreate --wait api simulator >/dev/null 2>&1

echo "Reloading the synthetic demo data as of the story day."
if ! $clock exec -T api php artisan migrate:fresh --seed --force --no-interaction >"$log" 2>&1; then
  # The seeder prints the synthetic demo API key; the failure is in the last lines.
  tail -n 40 "$log" | grep -v 'dei_' >&2
  exit 1
fi

$clock up -d --force-recreate --wait worker relay >/dev/null 2>&1

mkdir -p docs/assets
$compose --profile e2e run --rm --no-deps -T \
  -e SCREENSHOTS_CLOCK_OFFSET \
  -v "$(pwd)/docs/assets:/assets" \
  e2e sh -c 'npm ci --no-audit --no-fund && npx playwright test --config screenshots/playwright.config.ts && node screenshots/encode.mjs /tmp/screenshots /assets'
