#!/bin/sh
# The deployment of a stage, one script for every stage: make cloud-up runs its steps against the emulator (the local stage),
# and the workflow .github/workflows/deploy.yml runs the same steps against a real account (the prod stage; it has never
# run). Every tool runs in a container of compose.yaml: the CDK app (cdk-deploy for the emulator, cdk-release for an
# account), the serverless service of the functions and its scripts (functions-deploy, functions-release), the web app
# (web). A part marked EMULATOR ONLY exists because the emulator is not AWS, and says what AWS does instead
# (docs/deployment.md, "One script for every stage").
#
#   sh scripts/deploy.sh <local|prod> <step> [argument]
#
#   diff                    the differences of every stack the pipeline deploys with what is deployed (cdk diff)
#   stacks                  the stacks before the functions: messaging, storage, data, security, mail, alerts, compute,
#                           and in prod network and database
#   image                   builds the image of the functions, pushes it to the ECR repository of the stage, and prints
#                           it by digest, <registry>/<repository>@sha256:<digest>
#   migrate <image>         the console function on the new image first, the others still on theirs; then the migrations
#                           and the application identity of the database, with the console function
#   functions <image>       every function on the new image, by digest, with their triggers (osls deploy)
#   edge                    the stack edge, in front of the web app and the API
#   observability           the stack observability: metric filters, alarms, dashboard, saved queries
#   panel                   builds the web app for production and uploads it to the edge (prod: and invalidates)
#   gateway                 EMULATOR ONLY: the proxy in front of the distribution, which the emulator serves by host name
#   smoke                   the health of the API through the edge, and a direct call to API Gateway refused
#   versions                what runs: the image of each function, the stacks, the web app, the recent images
#   rollback-image <image>  every function back on an earlier image, by digest; the database stays as it is (forward only)
#   rollback-panel          the build of the web app before the current one
#
# prod reads its deployment parameters from the environment, which the workflow takes from its environment production:
# AWS_ACCOUNT_ID, AWS_REGION, EDGE_DOMAIN_NAME and EDGE_CERTIFICATE_ARN (the domain of the platform and its certificate in
# us-east-1, required), ALERTS_EMAIL (empty: no fallback email),
# RESERVED_CONCURRENCY (reserved, or unreserved for an account whose quota is too low), IMAGE_TAG (the commit), and the
# credentials of the role it assumed.
set -eu

stage="${1:-}"
step="${2:-}"
argument="${3:-}"

usage() {
  echo "usage: sh scripts/deploy.sh <local|prod> diff|stacks|image|migrate <image>|functions <image>|edge|observability|panel|gateway|smoke|versions|rollback-image <image>|rollback-panel" >&2
  exit 2
}

case "$stage" in
  local)
    compose="docker compose --env-file backend/.env --profile cloud"
    cdk="$compose run --rm --no-deps -T cdk-deploy"
    tools="$compose run --rm --no-deps -T -e STAGE=local functions-deploy"
    context=""
    # The emulator takes the admin token of the DIAN simulator of backend/.env (a development tool; prod has none), which
    # the shell of the container expands.
    # shellcheck disable=SC2016
    params='--param="simulatorToken=$SIMULATOR_TOKEN"'
    base="local/messaging local/storage local/data local/security local/mail local/alerts local/compute"
    ;;
  prod)
    # The domain is required: the functions take it as their public origin (APP_URL, the stateful domain of the session)
    # before the edge exists, so a deployment cannot wait for the name CloudFront would give the distribution.
    : "${AWS_ACCOUNT_ID:?the account of prod: AWS_ACCOUNT_ID}" "${EDGE_DOMAIN_NAME:?the domain of the platform: EDGE_DOMAIN_NAME}" "${EDGE_CERTIFICATE_ARN:?its certificate of ACM in us-east-1: EDGE_CERTIFICATE_ARN}" "${ALERTS_EMAIL?the fallback address of the alerts, or empty: ALERTS_EMAIL}"
    compose="docker compose --env-file backend/.env --profile release"
    cdk="$compose run --rm --no-deps -T cdk-release"
    tools="$compose run --rm --no-deps -T -e STAGE=prod -e EDGE_DOMAIN_NAME functions-release"
    context="-c account=$AWS_ACCOUNT_ID -c edgeDomainName=$EDGE_DOMAIN_NAME -c edgeCertificateArn=$EDGE_CERTIFICATE_ARN -c alertsEmail=$ALERTS_EMAIL"
    params="--param=account=$AWS_ACCOUNT_ID --param=reservedConcurrency=${RESERVED_CONCURRENCY:-reserved} --param=appUrl=https://$EDGE_DOMAIN_NAME --param=statefulDomains=$EDGE_DOMAIN_NAME"
    # The stack delivery holds the role of this pipeline: an administrator deploys it, never the pipeline itself.
    base="prod/messaging prod/storage prod/data prod/security prod/mail prod/alerts prod/network prod/database prod/compute"
    ;;
  *)
    usage
    ;;
esac

# osls deploy of the serverless service, after building the origin verifier and the notifier, with more parameters.
osls_deploy() {
  $tools sh -c "npm run --silent build && osls deploy --stage $stage $params $*"
}

case "$step" in
  diff)
    # shellcheck disable=SC2086
    $cdk cdk diff $base "$stage/edge" "$stage/observability" $context
    ;;

  stacks)
    if [ "$stage" = local ]; then
      # EMULATOR ONLY: the emulator may be new, so it is bootstrapped every time; an account is bootstrapped once, by
      # hand, with the execution policy its owner chooses (docs/deployment.md).
      $cdk sh -c "cdk bootstrap aws://000000000000/us-east-1 && cdk deploy $base --require-approval never --outputs-file cdk.out/outputs-local.json"
    else
      # shellcheck disable=SC2086
      $cdk cdk deploy $base --require-approval never $context
    fi
    ;;

  image)
    if [ -n "${BUILD-x}" ]; then
      docker compose --env-file backend/.env --profile cloud build lambda >&2
    fi

    if [ "$stage" = local ]; then
      # EMULATOR ONLY: the registry behind the ECR of the emulator, through its published port, path style, under the
      # first 16 characters of the image id; the reference is the AWS-shaped URI by digest, as in production.
      sh scripts/cloud/push-image.sh
    else
      registry="$AWS_ACCOUNT_ID.dkr.ecr.${AWS_REGION:-us-east-1}.amazonaws.com"
      repository="dian-einvoice-prod-functions"
      tag="${IMAGE_TAG:?the tag of the image, the commit: IMAGE_TAG}"
      $tools node scripts/release.mjs registry-password | docker login --username AWS --password-stdin "$registry" >&2
      # The tags of prod never move: a commit deployed before reuses the image it pushed.
      existing=$($tools node scripts/release.mjs image-digest "$tag")

      if [ -n "$existing" ]; then
        echo "$registry/$repository@$existing"
        exit 0
      fi

      docker tag dian-einvoice-lambda:latest "$registry/$repository:$tag"
      docker push --quiet "$registry/$repository:$tag" >&2
      digest=$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$registry/$repository:$tag" | grep "^$registry/$repository@" | head -1 | cut -d@ -f2)
      test -n "$digest" || { echo "The push of $registry/$repository:$tag left no digest." >&2; exit 1; }
      echo "$registry/$repository@$digest"
    fi
    ;;

  migrate)
    image="${argument:?the image, by digest}"

    if [ "$stage" = local ]; then
      # EMULATOR ONLY: the database of the emulated cloud on the MySQL of compose, and its migrations identity created
      # from its secret as the MySQL root user, as Aurora creates its master user (the SQL holds the password: it goes
      # to MySQL, never to the terminal).
      # shellcheck disable=SC2016
      $compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot' < scripts/cloud/database.sql
      identity="$($tools node scripts/database.mjs migrations-identity)"
      # shellcheck disable=SC2016
      printf '%s\n' "$identity" | $compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot'
      echo "The database's migrations identity, created from its secret."
    fi

    # The new image reaches the console function first: the functions that run meanwhile keep the code of the release
    # before, which the migrations must not break (make migrations-check). Before the first deployment, every function
    # takes the new image.
    current=$($tools node scripts/release.mjs current-image)
    osls_deploy "--param=image=${current:-$image}" "--param=consoleImage=$image"
    $tools node scripts/console.mjs migrate --force --no-interaction
    $tools node scripts/console.mjs db:application-identity
    ;;

  functions | rollback-image)
    image="${argument:?the image, by digest}"
    osls_deploy "--param=image=$image"
    ;;

  edge)
    if [ "$stage" = local ]; then
      # EMULATOR ONLY: the value of the secret of the edge as a parameter of the deployment, piped and never printed (the
      # emulator expands no dynamic reference outside RDS; prod resolves it from Secrets Manager), then the settings of
      # the behaviours the CloudFormation of the emulator drops (docs/limitations.md).
      # shellcheck disable=SC2016
      $tools node scripts/edge.mjs origin-secret | $cdk sh -c 'origin="$(cat)" && exec cdk deploy local/edge --require-approval never --parameters "OriginVerifyValue=$origin"'
      echo "The edge stack, with the edge secret as its parameter."
      $tools node scripts/edge.mjs reconcile
    else
      # shellcheck disable=SC2086
      $cdk cdk deploy prod/edge --require-approval never $context ${ORIGIN_VERIFY_VERSION:+--parameters OriginVerifyVersion=$ORIGIN_VERIFY_VERSION}
    fi
    ;;

  observability)
    # shellcheck disable=SC2086
    $cdk cdk deploy "$stage/observability" --exclusively --require-approval never $context

    if [ "$stage" = local ]; then
      # EMULATOR ONLY: the metrics of the alarms of metric math and the saved queries, which its CloudFormation drops.
      $tools node scripts/observability.mjs reconcile
    fi
    ;;

  panel)
    docker compose --env-file backend/.env run --rm --no-deps -T web pnpm build
    $tools node scripts/panel.mjs upload /workspace/web/dist
    ;;

  gateway)
    test "$stage" = local || { echo "The proxy in front of the edge exists in the emulated cloud only: in AWS the domain points at the distribution." >&2; exit 2; }
    $tools node scripts/gateway-env.mjs > infra/functions/.env.cloud
    $compose up -d --wait --force-recreate cloud-gateway
    ;;

  smoke)
    $tools node scripts/release.mjs smoke
    ;;

  versions)
    $tools node scripts/release.mjs versions
    ;;

  rollback-panel)
    $tools node scripts/panel.mjs rollback
    ;;

  *)
    usage
    ;;
esac
