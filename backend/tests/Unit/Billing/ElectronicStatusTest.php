<?php

namespace Tests\Unit\Billing;

use App\Modules\Billing\Enums\ElectronicStatus;
use PHPUnit\Framework\TestCase;

/**
 * The state machine of the electronic document chain. Every step checks its move against it, so
 * an allowed move too many could resend an accepted document, and one too few strands a chain.
 */
final class ElectronicStatusTest extends TestCase
{
    /** Every allowed move; any other pair is forbidden. */
    private const array ALLOWED = [
        'pending' => ['generated', 'failed'],
        'generated' => ['signed', 'failed'],
        'signed' => ['sending', 'failed'],
        'sending' => ['accepted', 'rejected', 'failed'],
        // Resend starts a new run from the generation.
        'rejected' => ['pending'],
        // Retry now starts again from the step that failed.
        'failed' => ['pending', 'generated', 'signed'],
        // An accepted document never changes.
        'accepted' => [],
    ];

    public function test_allowed_and_forbidden_moves(): void
    {
        foreach (ElectronicStatus::cases() as $from) {
            foreach (ElectronicStatus::cases() as $to) {
                $allowed = in_array($to->value, self::ALLOWED[$from->value], true);

                $this->assertSame($allowed, $from->canMoveTo($to), sprintf('%s → %s should be %s.', $from->value, $to->value, $allowed ? 'allowed' : 'forbidden'));
            }
        }
    }

    public function test_a_chain_ends_in_accepted_rejected_or_failed(): void
    {
        $final = array_map(fn (ElectronicStatus $status): string => $status->value, array_values(array_filter(ElectronicStatus::cases(), fn (ElectronicStatus $status): bool => $status->isFinal())));

        $this->assertSame(['accepted', 'rejected', 'failed'], $final);
        $this->assertSame(['pending', 'generated', 'signed', 'sending'], array_map(fn (ElectronicStatus $status): string => $status->value, ElectronicStatus::processing()));
    }
}
