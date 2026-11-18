<?php

namespace App\Modules\Developers\Receiver;

use RuntimeException;

/** The local webhook receiver (inside the simulator service) did not answer as expected. */
final class ReceiverUnavailable extends RuntimeException {}
