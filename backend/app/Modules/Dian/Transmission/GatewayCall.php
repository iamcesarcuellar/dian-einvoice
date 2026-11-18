<?php

namespace App\Modules\Dian\Transmission;

/**
 * The result of one call to the DIAN web service: how it ended, how long it took and, when the
 * service answered, its validation result. Transport failures are results too, never exceptions.
 */
final readonly class GatewayCall
{
    public function __construct(
        public string $operation,
        public TransmissionOutcome $outcome,
        public int $durationMs,
        public ?int $httpStatus = null,
        public ?DianAnswer $answer = null,
        public ?string $error = null,
        /** The request went out and no answer came back, so the service may have processed it. */
        public bool $mayHaveArrived = false,
    ) {}
}
