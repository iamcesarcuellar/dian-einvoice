<?php

namespace App\Modules\Dian\Simulator;

use RuntimeException;

/** The admin API of the local DIAN simulator did not answer, or answered with an error. */
final class SimulatorUnavailable extends RuntimeException {}
