#!/bin/sh
# Keeps a long-running process alive inside its container, such as the queue worker or the outbox
# relay: when it exits (memory limit, fatal error) it starts again a second later. SIGTERM or SIGINT
# is passed on to it, so it stops gracefully, and then the loop ends. The container itself keeps
# restart: "no".
pid=""

stop() {
  if [ -n "$pid" ]; then
    kill -TERM "$pid" 2>/dev/null
    wait "$pid"
  fi
  exit 0
}

trap stop TERM INT

while true; do
  "$@" &
  pid=$!
  wait "$pid"
  sleep 1
done
