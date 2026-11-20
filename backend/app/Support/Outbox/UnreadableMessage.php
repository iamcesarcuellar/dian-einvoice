<?php

namespace App\Support\Outbox;

use RuntimeException;

/**
 * A message of a queue that no handler can take: not a valid outbox event, or an event of a type this
 * queue has no job for. Its job fails on every receive, so the redrive policy of the queue moves it to
 * the dead-letter queue.
 */
final class UnreadableMessage extends RuntimeException {}
