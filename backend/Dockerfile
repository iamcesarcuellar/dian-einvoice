# Images of the backend, from one Dockerfile.
#
# production and development: FrankenPHP in classic mode (no worker), one container without nginx, for the
# local stack. The same image runs the API, the queue workers and the outbox relay (compose services api,
# worker and relay); the code is mounted, nothing is copied. development (the one compose builds) adds
# php.ini-development and the tools of the local checks.
#
# lambda: the image of the functions of the emulated cloud and of production, on
# the official image of Bref for the same PHP version, with the application and its production
# dependencies only. One image for every function: each one chooses its Bref runtime (BREF_RUNTIME) and its
# handler (the command of the image). It is the artifact that would go to Amazon ECR: the emulator runs it
# from its own ECR.
#
# redis is the client of the queue, the cache and the sessions of the local stack. pcntl stays in
# production: queue:work needs it to enforce --timeout and to stop gracefully on SIGTERM, the outbox relay
# to stop between batches, and the consumer functions to stop a job that outlives its timeout;
# billing:stress and payments:check also use it to fork, and are development tools.
FROM dunglas/frankenphp:1-php8.5-alpine AS production

# expose_php off: answers do not name the PHP version (X-Powered-By).
RUN install-php-extensions pdo_mysql intl zip pcntl redis \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'expose_php = Off\n' > "$PHP_INI_DIR/conf.d/zz-security.ini" \
    && chmod -R a+rwX /config /data

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# The container runs with the host user id, which has no home directory in the image.
ENV SERVER_NAME=:8000 \
    HOME=/tmp

WORKDIR /app

EXPOSE 8000

# Development only: xmlsec1 lets dian:check verify XML signatures with a tool independent of our code;
# poppler-utils (pdfinfo, pdftotext) lets delivery:check read the PDF drawn by dompdf with a tool
# independent of the one that wrote it.
FROM production AS development

RUN apk add --no-cache xmlsec poppler-utils \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# The functions, built in two steps: Composer and the build-time artisan commands stay in this one.
FROM bref/php-85:3.0.12 AS lambda-build

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/task

# Bref ships intl and redis disabled; the application uses them, as the local image does.
COPY docker/lambda/php.ini php/conf.d/zz-application.ini

# The certificate authorities of Amazon RDS, which sign the server certificate of the managed database: the
# functions verify it on every connection in prod (DB_SSL_CA), and encrypt the connection with it everywhere.
# Pinned by its SHA-256 (docker/lambda/fetch-rds-bundle.php).
ARG RDS_CA_BUNDLE_SHA256=90a6f1a7978a1b1b096dca6c57511ca48d3b8cf052f8e704baf358f34ea2f21b
COPY docker/lambda/fetch-rds-bundle.php /tmp/fetch-rds-bundle.php
RUN php /tmp/fetch-rds-bundle.php "$RDS_CA_BUNDLE_SHA256" /opt/rds/global-bundle.pem

# Production dependencies only, from the lock file; the autoloader comes once the code is in.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

COPY artisan ./
COPY app app
COPY bootstrap/app.php bootstrap/providers.php bootstrap/
RUN mkdir bootstrap/cache
COPY config config
COPY database database
COPY lang lang
COPY public public
COPY resources resources
COPY routes routes
COPY docker/lambda/check-extensions.php /tmp/check-extensions.php

# The build fails when PHP lacks an extension the application requires (the ext-* of composer.json) or one
# its dependencies require, or when the API's messages in either language are missing. The manifests of the
# packages and of the service providers are written now, into bootstrap/cache, because the file system of a
# function is read-only but for /tmp; the configuration is cached in /tmp when each execution environment
# starts (Bref's Laravel bridge), with its secrets.
RUN php /tmp/check-extensions.php composer.json \
    && composer check-platform-reqs --no-dev --no-interaction \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && LOG_CHANNEL=stderr php artisan list --raw > /dev/null \
    && test -f bootstrap/cache/packages.php && test -f bootstrap/cache/services.php \
    && test -f lang/en/problems.php && test -f lang/es/validation.php

FROM bref/php-85:3.0.12 AS lambda

# Root owns every file and nobody else can write them: the functions run as an unprivileged user, as
# Lambda does, and write only to /tmp.
COPY --from=lambda-build /var/task /var/task
COPY --from=lambda-build /opt/rds/global-bundle.pem /opt/rds/global-bundle.pem

# PHP-FPM of the function api logs from warning up: its notices (started, ready, terminating) would reach the log
# of the function as plain text, where every line is JSON (docs/observability.md). The application logs its own
# errors as JSON, uncaught and fatal ones included; the build fails when the setting of Bref moves.
RUN sed -i 's/^;log_level = .warning.$/log_level = warning/' /opt/bref/etc/php-fpm.conf \
    && grep -qx 'log_level = warning' /opt/bref/etc/php-fpm.conf

# Nothing depends on the working directory: Bref finds the application in LAMBDA_TASK_ROOT (/var/task)
# and Laravel runs its commands from its base path. The local emulator checks that the user of Lambda may
# enter every directory of the working directory by copying each one out of the container, which for
# /var/task takes seconds at every cold start; from / it checks nothing.
WORKDIR /
