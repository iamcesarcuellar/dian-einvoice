<?php

namespace App\Modules\Dian\Console;

use App\Modules\Dian\Transmission\DianGateway;
use App\Modules\Dian\Transmission\TransmissionOutcome;
use Illuminate\Console\Command;

/**
 * An operation command of the runbooks (docs/runbooks/dian-errors.md): whether the web service of the DIAN answers
 * now. It asks GetStatus for a document that cannot exist (a CUFE of zeros), so it changes nothing: an answer that
 * the document is not known means the service is up; no answer, an HTTP 5xx or a body that is not the service's response
 * means it is down or failing, and the transmissions wait on their retries. The call is a real one, logged as
 * every call (dian.call). It exits 0 when the service answers and 1 when it does not, so a script can wait on it.
 *
 *   make cloud-artisan ARGS="dian:probe"
 */
final class DianProbeCommand extends Command
{
    protected $signature = 'dian:probe';

    protected $description = 'Whether the DIAN web service responds now: GetStatus for a document that cannot exist (an operation command of the runbooks)';

    /** A CUFE no document has: SHA-384 in hexadecimal is 96 characters, never all zeros. */
    private const string NO_DOCUMENT = '000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000';

    public function handle(DianGateway $gateway): int
    {
        $call = $gateway->getStatus(self::NO_DOCUMENT);
        $answered = in_array($call->outcome, [TransmissionOutcome::NotFound, TransmissionOutcome::Found], true);

        $this->line(sprintf(
            'The DIAN web service (%s, %s) %s GetStatus in %.2f s: %s%s.',
            $gateway->name(),
            $gateway->endpoint(),
            $answered ? 'responded to' : 'did not respond to',
            $call->durationMs / 1000,
            $call->outcome->value,
            $call->httpStatus === null ? '' : " (HTTP {$call->httpStatus})",
        ));
        $this->line($answered
            ? 'It is up: transmissions get their responses again; the chain\'s dead letters can be moved back (docs/runbooks/dead-letters.md).'
            : 'It is down or failing: the transmissions wait on their retries, and those that gave up wait in the chain\'s dead-letter queue (docs/runbooks/dian-errors.md).');

        return $answered ? self::SUCCESS : self::FAILURE;
    }
}
