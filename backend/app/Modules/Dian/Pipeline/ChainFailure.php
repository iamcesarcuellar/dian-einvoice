<?php

namespace App\Modules\Dian\Pipeline;

use RuntimeException;

/**
 * Why a chain step ended the document as failed; the job goes to the dead letter queue with it.
 */
final class ChainFailure extends RuntimeException {}
