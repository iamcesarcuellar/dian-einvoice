<?php

namespace App\Modules\Dian\Console;

use App\Modules\Dian\Transmission\DianGateway;
use App\Modules\Dian\Transmission\TransmissionOutcome;
use Illuminate\Console\Command;

/**
 * An operation command of the runbooks (docs/runbooks/dian-errors.md): whether the web service of the DIAN answers
 * now. It asks GetStatus for a document that cannot exist (a CUFE of zeros), so it changes nothing: an answer that
 * the document is not known means the service is up; no answer, an HTTP 5xx or a body that is not an answer of the
 * service means it is down or failing, and the transmissions wait on their retries. The call is a real one, logged as
 * every call (dian.call). It exits 0 when the service answers and 1 when it does not, so a script can wait on it.
 *
 *   make cloud-artisan ARGS="dian:probe"
 */
final class DianProbeCommand extends Command
{
    protected $signature = 'dian:probe';

    protected $description = 'Whether the web service of the DIAN answers now: GetStatus of a document that cannot exist (an operation command of the runbooks)';

    /** A CUFE no document has: SHA-384 in hexadecimal is 96 characters, never all zeros. */
    private const string NO_DOCUMENT = '000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000';

    public function handle(DianGateway $gateway): int
    {
        $call = $gateway->getStatus(self::NO_DOCUMENT);
        $answered = in_array($call->outcome, [TransmissionOutcome::NotFound, TransmissionOutcome::Found], true);

        $this->line(sprintf(
            'The web service of the DIAN (%s, %s) %s GetStatus in %.2f s: %s%s.',
            $gateway->name(),
            $gateway->endpoint(),
            $answered ? 'answered' : 'did not answer',
            $call->durationMs / 1000,
            $call->outcome->value,
            $call->httpStatus === null ? '' : " (HTTP {$call->httpStatus})",
        ));
        $this->line($answered
            ? 'It is up: transmissions get their answers again; the dead letters of the chain can be moved back (docs/runbooks/dead-letters.md).'
            : 'It is down or failing: the transmissions wait on their retries, and those that gave up wait in the dead-letter queue of the chain (docs/runbooks/dian-errors.md).');

        return $answered ? self::SUCCESS : self::FAILURE;
    }
}
