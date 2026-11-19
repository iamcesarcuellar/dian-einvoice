<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\InvitationStatus;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SensitiveParameter;

/**
 * An invitation to join a company with a role, sent by email. Only the SHA-256 of its token is
 * stored; the token itself travels only in the email link. It expires VALID_DAYS after it was
 * created or last resent, and resending it replaces the token, so only the newest link works.
 * The page of the link reads it without a current company (withoutGlobalScope(CompanyScope)).
 */
class TeamInvitation extends Model
{
    use BelongsToCompany, HasUlids;

    /** Days a link works after the invitation is sent. */
    public const int VALID_DAYS = 7;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'role',
        'token_hash',
        'invited_by',
        'expires_at',
        'last_sent_at',
        'accepted_at',
        'accepted_by',
        'canceled_at',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /** What is stored of a token: its SHA-256, in hexadecimal. */
    public static function hashToken(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    public function status(): InvitationStatus
    {
        return match (true) {
            $this->accepted_at !== null => InvitationStatus::Accepted,
            $this->canceled_at !== null => InvitationStatus::Canceled,
            $this->expires_at->isPast() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    /**
     * Invitations neither accepted nor canceled: pending or expired.
     *
     * @param  Builder<TeamInvitation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('canceled_at');
    }

    /**
     * Who sent it; null once that user is deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
