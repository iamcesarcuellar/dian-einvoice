#!/bin/sh
# make cloud-up: pushes the image of the functions (dian-einvoice-lambda, target lambda of backend/Dockerfile)
# to the ECR repository of the local stage in the local AWS emulator, under the first 16 characters of its
# image id, and prints the reference the functions are deployed with: the AWS-shaped URI of the repository
# by digest, as in production. The emulator pulls it from its own registry, which the push goes to through
# its published port (127.0.0.1:8604), path style.
set -eu

image="dian-einvoice-lambda:latest"
repository="dian-einvoice-local-functions"
registry="localhost:8604/000000000000/us-east-1"

id=$(docker image inspect --format '{{.Id}}' "$image")
tag=$(echo "$id" | sed 's/^sha256://' | cut -c1-16)
target="$registry/$repository:$tag"

docker tag "$image" "$target"
docker push --quiet "$target" >/dev/null

digest=$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$target" | grep "^$registry/$repository@" | head -1 | cut -d@ -f2)

if [ -z "$digest" ]; then
  echo "The push of $target left no digest." >&2
  exit 1
fi

echo "000000000000.dkr.ecr.us-east-1.amazonaws.com/$repository@$digest"
