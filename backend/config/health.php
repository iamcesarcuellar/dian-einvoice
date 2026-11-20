<?php

// What /api/v1/health and php artisan health:check verify (App\Support\Health\HealthChecks): the database
// and Redis locally; the emulated cloud (backend/.env.cloud) checks the cache store and the queue instead
// of Redis.
return [

    'checks' => array_values(array_filter(array_map('trim', explode(',', (string) env('HEALTH_CHECKS', 'database,redis'))))),

];
