<?php

namespace App\Support\Lambda;

use RuntimeException;

/** A command of ArtisanHandler exited with an error: the invocation fails, with what the command printed. */
final class CommandFailed extends RuntimeException {}
