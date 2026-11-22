<?php

// make screenshots only (scripts/screenshots/compose.yaml, through clock.ini): the story clock of the backend.
// Every moment the application reads through Carbon (now(), Carbon::now(), the queue, the sessions) moves by
// SCREENSHOTS_CLOCK_OFFSET seconds and keeps ticking. The demo seeder moves the clock to each moment of its plan
// and puts back the clock it found, this one.

$offset = getenv('SCREENSHOTS_CLOCK_OFFSET');

if (is_string($offset) && preg_match('/^-?\d+$/', $offset) === 1 && (int) $offset !== 0) {
    require_once '/app/vendor/autoload.php';

    $seconds = (int) $offset;

    Carbon\Carbon::setTestNow(static fn (Carbon\CarbonInterface $now): Carbon\CarbonInterface => $now->addSeconds($seconds));
}
