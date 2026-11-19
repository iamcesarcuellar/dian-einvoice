#!/bin/sh
# The environment file of the backend (backend/.env, never committed), created from backend/.env.example
# when missing. A setting the example has and the file lacks (added by a later version) is appended
# with the value of the example; a setting already in the file is never changed. Then each secret of
# the local stack that is still empty gets a random value: the Redis password and the admin token of
# the DIAN simulator. Laravel reads them from this file, and compose (make passes --env-file
# backend/.env) hands the same values to the redis and simulator services. Running it again changes
# nothing. The well-known token of earlier versions (local-simulator-token) and REDIS_PASSWORD=null
# count as empty.
set -eu

file=${1:-backend/.env}
example=backend/.env.example

if [ ! -f "$file" ]; then
  cp "$example" "$file"
fi

missing=$(grep -E '^[A-Z][A-Z0-9_]*=' "$example" | while IFS= read -r line; do
  name=${line%%=*}
  grep -Eq "^${name}=" "$file" || printf '%s\n' "$line"
done)

if [ -n "$missing" ]; then
  printf '\n# Added from %s by make env.\n%s\n' "$example" "$missing" >> "$file"
  echo "Added to ${file} from ${example}: $(printf '%s\n' "$missing" | cut -d= -f1 | tr '\n' ' ')"
fi

for name in REDIS_PASSWORD DIAN_SIMULATOR_TOKEN; do
  if grep -Eq "^${name}=" "$file" && ! grep -Eq "^${name}=(|null|local-simulator-token)$" "$file"; then
    continue
  fi

  value=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')
  grep -v "^${name}=" "$file" > "$file.tmp" || true
  printf '%s=%s\n' "$name" "$value" >> "$file.tmp"
  cat "$file.tmp" > "$file"
  rm -f "$file.tmp"
  echo "Generated ${name} in ${file}."
done
