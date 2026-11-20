#!/bin/sh
# What the local AWS emulator created for this project through the Docker socket (compose service floci,
# profile cloud): the containers of the functions, the registry behind its ECR, its web console, and their
# volumes. Every one carries the label dian-einvoice=floci (FLOCI_DOCKER_EXTRA_LABELS of compose.yaml) and
# the name of the project; only those that carry both are listed or removed, never anything else.
#
#   sh scripts/cloud/containers.sh list|remove|remove-volumes|kill-functions
set -eu

label="dian-einvoice=floci"

containers() {
  docker ps -a --filter "label=$label" --format '{{.ID}} {{.Names}}' | while read -r id name; do
    case "$name" in
      *dian-einvoice*) echo "$id $name" ;;
    esac
  done
}

case "${1:-list}" in
  list)
    containers
    ;;
  remove)
    ids=$(containers | cut -d' ' -f1)
    if [ -n "$ids" ]; then
      # shellcheck disable=SC2086
      docker rm --force $ids >/dev/null
      echo "Removed $(echo "$ids" | wc -l | tr -d ' ') containers the emulator had created for this project."
    fi
    ;;
  remove-volumes)
    docker volume ls --filter "label=$label" --format '{{.Name}}' | while read -r volume; do
      case "$volume" in
        *dian-einvoice*) docker volume rm --force "$volume" >/dev/null && echo "Removed the volume $volume." ;;
      esac
    done
    ;;
  kill-functions)
    # The running containers of the functions only: dian-einvoice-dian-einvoice-<function>-<id>.
    ids=$(docker ps --filter "label=$label" --filter "name=^dian-einvoice-dian-einvoice-" --format '{{.ID}}')
    if [ -n "$ids" ]; then
      # shellcheck disable=SC2086
      docker kill $ids >/dev/null
    fi
    echo "$ids" | grep -c . || true
    ;;
  *)
    echo "usage: $0 list|remove|remove-volumes|kill-functions" >&2
    exit 2
    ;;
esac
