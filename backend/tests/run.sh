#!/bin/sh
# Runs the backend's tests (PHPUnit, with the arguments given), then prints the memory peak and limit of the
# container they ran in, read from its cgroup (v2). make test-backend runs it, so the log of every run shows the
# margin. The concurrency tests set the peak: they fork the test runner into up to 8 processes
# (tests/Support/Parallel.php). The exit status is PHPUnit's, 137 included when the kernel ends it for lack of memory.
vendor/bin/phpunit "$@"
status=$?

cgroup=/sys/fs/cgroup
mib() { echo "$(($1 / 1048576)) MiB"; }

if [ -r "$cgroup/memory.peak" ] && [ -r "$cgroup/memory.max" ]; then
  read -r peak < "$cgroup/memory.peak"
  read -r limit < "$cgroup/memory.max"
  if [ "$limit" = max ]; then limit="with no limit"; else limit="of a $(mib "$limit") limit"; fi
  kills=$(sed -n 's/^oom_kill //p' "$cgroup/memory.events")
  echo "Test container memory: peak $(mib "$peak") $limit (cgroup memory.peak, page cache included); OOM kills: ${kills:-0}"
else
  echo "Test container memory: unknown (memory.peak needs cgroup v2 and Linux 5.19 or later)"
fi

exit "$status"
