<?php

namespace App\Modules\Identity\Team;

use App\Modules\Identity\Enums\InvitationStatus;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Mail\TeamInvitationMail;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Models\TeamInvitation;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\CompanyScope;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Invitations to join the current company, by email. Inviting stores only the SHA-256 of a random
 * token and emails the token in a link to the web app (APP_URL/invitations/{token}); the link works
 * for seven days after the invitation is sent, and resending it replaces the token, so only the
 * newest link works. The email goes out right away through Laravel's mailer (the local mailbox
 * here), after the invitation is committed and never through the queue, so the token is never
 * stored anywhere; a failed send is reported and the invitation can be resent.
 *
 * Accepting happens from the link, without a current company: findByToken(), then acceptAs() for
 * an address that already has an account (signed in as it) or acceptAsNewUser() for one that does
 * not. Every change locks the row of the company and then the invitation, always in that order (as
 * TeamMembers does), so a link is used once and changes to one team never deadlock each other.
 */
final class TeamInvitations
{
    /** Letters and digits of a token. */
    private const int TOKEN_LENGTH = 48;

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * Pending and expired invitations of the current company, newest first.
     *
     * @return Collection<int, TeamInvitation>
     */
    public function open(): Collection
    {
        return TeamInvitation::query()
            ->open()
            ->with('inviter')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Invites an address to the current company. An address that belongs to a member, or that has
     * a pending invitation, is refused on the email field; expired invitations to it give way to
     * the new one.
     *
     * @return array{0: TeamInvitation, 1: bool} The invitation, and whether the mail server took its email.
     */
    public function invite(string $email, Role $role, User $inviter): array
    {
        $email = Str::lower(trim($email));
        TeamMembers::ensureMayGrant($this->currentCompany, $role);
        $token = Str::random(self::TOKEN_LENGTH);

        $invitation = DB::transaction(function () use ($email, $role, $inviter, $token): TeamInvitation {
            $this->lockCompany();

            if ($this->isMember($email)) {
                throw ValidationException::withMessages(['email' => "{$email} already belongs to this company."]);
            }

            $earlier = TeamInvitation::query()->open()->where('email', $email)->lockForUpdate()->get();

            if ($earlier->contains(fn (TeamInvitation $invitation): bool => $invitation->status() === InvitationStatus::Pending)) {
                throw ValidationException::withMessages(['email' => "{$email} already has a pending invitation to this company; resend it instead."]);
            }

            foreach ($earlier as $expired) {
                $expired->fill(['canceled_at' => Carbon::now()])->save();
            }

            return TeamInvitation::create([
                'email' => $email,
                'role' => $role,
                'token_hash' => TeamInvitation::hashToken($token),
                'invited_by' => $inviter->getKey(),
                'expires_at' => Carbon::now()->addDays(TeamInvitation::VALID_DAYS),
            ]);
        });

        return [$invitation, $this->send($invitation, $token)];
    }

    /**
     * Sends a pending or expired invitation again, with a new token and seven more days.
     *
     * @return array{0: TeamInvitation, 1: bool} The invitation, and whether the mail server took its email.
     */
    public function resend(TeamInvitation $invitation): array
    {
        $token = Str::random(self::TOKEN_LENGTH);

        $invitation = DB::transaction(function () use ($invitation, $token): TeamInvitation {
            $this->lockCompany();
            $invitation = TeamInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            match ($invitation->status()) {
                InvitationStatus::Accepted => throw new ConflictHttpException('This invitation was already accepted.'),
                InvitationStatus::Canceled => throw new ConflictHttpException('This invitation was canceled; send a new one instead.'),
                default => null,
            };

            if ($invitation->role === Role::Owner && $this->currentCompany->role() !== Role::Owner) {
                throw new AccessDeniedHttpException('Only an owner can resend an invitation to become an owner.');
            }

            if ($this->isMember($invitation->email)) {
                throw new ConflictHttpException("{$invitation->email} already belongs to this company; cancel this invitation instead.");
            }

            $invitation->fill([
                'token_hash' => TeamInvitation::hashToken($token),
                'expires_at' => Carbon::now()->addDays(TeamInvitation::VALID_DAYS),
            ])->save();

            return $invitation;
        });

        return [$invitation, $this->send($invitation, $token)];
    }

    /** Cancels an invitation that was not accepted; its link stops working. Canceling twice is harmless. */
    public function cancel(TeamInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $this->lockCompany();
            $invitation = TeamInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            if ($invitation->status() === InvitationStatus::Accepted) {
                throw new ConflictHttpException('This invitation was already accepted; remove the member instead.');
            }

            if ($invitation->canceled_at === null) {
                $invitation->fill(['canceled_at' => Carbon::now()])->save();
            }
        });
    }

    /** The invitation of a link, of any company, with its company and inviter; null when no invitation has that token. */
    public function findByToken(string $token): ?TeamInvitation
    {
        if ($token === '' || strlen($token) > 255) {
            return null;
        }

        return TeamInvitation::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with(['company', 'inviter'])
            ->where('token_hash', TeamInvitation::hashToken($token))
            ->first();
    }

    /** Whether the address of the invitation already has an account. */
    public function hasAccount(TeamInvitation $invitation): bool
    {
        return User::query()->where('email', $invitation->email)->exists();
    }

    /** Only a pending invitation can be accepted; the others answer 409 with the reason. */
    public function ensurePending(TeamInvitation $invitation): void
    {
        match ($invitation->status()) {
            InvitationStatus::Pending => null,
            InvitationStatus::Expired => throw new ConflictHttpException('This invitation has expired. Ask the company to send it again.'),
            InvitationStatus::Canceled => throw new ConflictHttpException('This invitation was canceled.'),
            InvitationStatus::Accepted => throw new ConflictHttpException('This invitation has already been accepted.'),
        };
    }

    /**
     * The user the invitation was sent to, signed in, joins the company with the invited role;
     * when they already belong to it, nothing changes but the invitation.
     */
    public function acceptAs(TeamInvitation $invitation, User $user): TeamInvitation
    {
        return DB::transaction(function () use ($invitation, $user): TeamInvitation {
            $invitation = $this->lockPending($invitation);

            $member = Membership::query()
                ->where('company_id', $invitation->company_id)
                ->where('user_id', $user->getKey())
                ->exists();

            if (! $member) {
                Membership::create([
                    'company_id' => $invitation->company_id,
                    'user_id' => $user->getKey(),
                    'role' => $invitation->role,
                ]);
            }

            $invitation->fill(['accepted_at' => Carbon::now(), 'accepted_by' => $user->getKey()])->save();

            return $invitation;
        });
    }

    /** An address without an account: its user is created with the name and password given, and joins the company. */
    public function acceptAsNewUser(TeamInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password): User {
            $invitation = $this->lockPending($invitation);

            if (User::query()->where('email', $invitation->email)->exists()) {
                throw new ConflictHttpException("An account for {$invitation->email} exists now. Sign in to accept the invitation.");
            }

            $user = User::create(['name' => $name, 'email' => $invitation->email, 'password' => $password]);

            Membership::create([
                'company_id' => $invitation->company_id,
                'user_id' => $user->getKey(),
                'role' => $invitation->role,
            ]);

            $invitation->fill(['accepted_at' => Carbon::now(), 'accepted_by' => $user->getKey()])->save();

            return $user;
        });
    }

    /** Emails the link, once the invitation is committed. A failure is reported and returns false. */
    private function send(TeamInvitation $invitation, string $token): bool
    {
        $invitation->loadMissing(['company', 'inviter']);

        try {
            Mail::to($invitation->email)->send(new TeamInvitationMail($invitation, $token));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        $invitation->fill(['last_sent_at' => Carbon::now()])->save();

        return true;
    }

    /** Locks the company of the invitation and then the invitation, in the order every team change takes them. */
    private function lockPending(TeamInvitation $invitation): TeamInvitation
    {
        Company::query()->whereKey($invitation->company_id)->lockForUpdate()->first(['id']);

        $locked = TeamInvitation::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereKey($invitation->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $this->ensurePending($locked);

        return $locked;
    }

    /** Serializes the changes to the team of the current company. */
    private function lockCompany(): void
    {
        Company::query()->whereKey($this->currentCompany->id())->lockForUpdate()->first(['id']);
    }

    private function isMember(string $email): bool
    {
        return Membership::query()
            ->where('company_id', $this->currentCompany->id())
            ->whereHas('user', fn (Builder $user) => $user->where('email', $email))
            ->exists();
    }
}
