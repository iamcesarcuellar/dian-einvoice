<?php

namespace App\Modules\Identity\Team;

use App\Modules\Identity\Enums\InvitationStatus;
use App\Modules\Identity\Mail\TeamInvitationMail;
use App\Modules\Identity\Models\TeamInvitation;
use App\Modules\Identity\Tenancy\CompanyScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use SensitiveParameter;

/**
 * Sends the email of an invitation from the queue, retried on failure. The job carries the token of
 * the link, so its payload is encrypted with the application key (ShouldBeEncrypted): the queue and
 * failed_jobs hold it only encrypted, and the database only its hash. An invitation that is no
 * longer pending, or that was resent with a newer link since, is not sent.
 */
final class SendTeamInvitation implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Attempts before the job is given up and kept in failed_jobs. */
    public int $tries = 5;

    /**
     * Seconds before each retry.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120];

    /** Seconds before the worker stops the job; the SMTP connection has its own timeout. */
    public int $timeout = 30;

    public function __construct(
        public readonly string $invitationId,
        #[SensitiveParameter] public readonly string $token,
    ) {}

    public function handle(): void
    {
        $invitation = TeamInvitation::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with(['company', 'inviter'])
            ->find($this->invitationId);

        if ($invitation === null
            || $invitation->status() !== InvitationStatus::Pending
            || ! hash_equals((string) $invitation->token_hash, TeamInvitation::hashToken($this->token))) {
            return;
        }

        Mail::to($invitation->email)->send(new TeamInvitationMail($invitation, $this->token));

        $invitation->forceFill(['last_sent_at' => Carbon::now()])->save();
    }
}
