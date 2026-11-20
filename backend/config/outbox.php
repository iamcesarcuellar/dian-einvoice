<?php

// How committed outbox events reach the topic or the queue (App\Support\Outbox). Locally a relay process
// runs all the time (compose service relay, php artisan outbox:relay). The functions of the emulated cloud
// and of production have no process that runs all the time, so the writer publishes its own events right
// after its transaction commits (the fast path), and the relay function publishes once a minute whatever
// was left behind (the safety net, php artisan outbox:publish-pending).
return [

    // The fast path: whoever recorded events publishes them after the commit, before it answers. Off
    // locally, where the relay does it; on in the functions.
    'publish_after_commit' => (bool) env('OUTBOX_PUBLISH_AFTER_COMMIT', false),

    // The safety net takes only events older than this, in seconds, so it leaves the fresh ones to the
    // writer that is about to publish them.
    'pending_after_seconds' => (int) env('OUTBOX_PENDING_AFTER_SECONDS', 10),

    // Seconds one run of the safety net may spend publishing before it leaves the rest to the next run,
    // below the timeout of the relay function.
    'pending_budget_seconds' => (int) env('OUTBOX_PENDING_BUDGET_SECONDS', 40),

];
