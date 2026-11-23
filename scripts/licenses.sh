#!/bin/sh
# make licenses: docs/third-party-licenses.md, the production dependencies of the five applications with their
# versions and licenses, as their lock files install them (scripts/security/licenses.mjs and third-party.mjs, each
# list taken in the container of its application). It fails when a license is not compatible with the MIT License of
# this project (scripts/security/compatibility.mjs). With --check (make security) it writes nothing and also fails
# when the file is not what the lock files give.
#
#   sh scripts/licenses.sh [--check]
set -eu

compose="docker compose --env-file backend/.env"
security="$(pwd)/scripts/security"
lists=$(mktemp -d)
trap 'rm -rf "$lists"' EXIT

$compose run --rm --no-deps -T api composer licenses --no-dev --format=json \
  | $compose run --rm --no-deps -T -v "$security:/security:ro" web node /security/licenses.mjs composer backend --json > "$lists/backend.json"
$compose run --rm --no-deps -T -v "$security:/security:ro" web \
  sh -c 'pnpm licenses list --prod --json | node /security/licenses.mjs pnpm web-app --json' > "$lists/web-app.json"
$compose --profile tools run --rm -T -v "$security:/security:ro" simulator-dev \
  sh -c 'cd /workspace && npm ls --omit=dev --all --parseable | node /security/licenses.mjs npm simulator --json' > "$lists/simulator.json"
$compose --profile cloud run --rm --no-deps -T -v "$security:/security:ro" cdk \
  sh -c 'cd /workspace && npm ls --omit=dev --all --parseable | node /security/licenses.mjs npm cdk-app --json' > "$lists/cdk-app.json"
$compose --profile cloud run --rm --no-deps -T -v "$security:/security:ro" functions \
  sh -c 'cd /workspace && npm ls --omit=dev --all --parseable | node /security/licenses.mjs npm functions --json' > "$lists/functions.json"

# The files of others that live in the repository (scripts/security/notices.mjs, from third-party-files.json): each
# listed file exists, an unchanged one keeps its SHA-256 and its notice, NOTICE names it, and no other file looks like
# the work of a third party. git lists the files, those not committed yet included.
git ls-files --cached --others --exclude-standard \
  | $compose run --rm --no-deps -T -v "$security:/security:ro" -v "$(pwd):/repo:ro" web node /security/notices.mjs /repo

$compose run --rm --no-deps -T -v "$security:/security:ro" -v "$lists:/licenses:ro" web \
  node /security/third-party.mjs /licenses > "$lists/third-party-licenses.md"

if [ "${1:-}" = "--check" ]; then
  if ! cmp -s "$lists/third-party-licenses.md" docs/third-party-licenses.md; then
    echo "docs/third-party-licenses.md is not what the lock files give: run make licenses." >&2
    diff -u docs/third-party-licenses.md "$lists/third-party-licenses.md" | head -n 40 >&2 || true
    exit 1
  fi

  echo "docs/third-party-licenses.md matches the lock files, and every license in it is compatible with the MIT License."
else
  cp "$lists/third-party-licenses.md" docs/third-party-licenses.md
  echo "docs/third-party-licenses.md written: every license in it is compatible with the MIT License."
fi
