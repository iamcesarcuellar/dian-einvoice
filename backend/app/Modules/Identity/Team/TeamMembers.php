<?php

namespace App\Modules\Identity\Team;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The members of the current company and their roles, for its owners and admins. A company always
 * keeps at least one owner; only owners grant the owner role or change and remove an owner, so an
 * admin can never take a company away from its owners; and nobody removes themselves from here.
 * Every change locks the row of the company first, so two owners demoting each other at the same
 * time cannot leave it without owners.
 */
final class TeamMembers
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * Owners first, then admins, members and viewers, each by name.
     *
     * @return Collection<int, Membership>
     */
    public function list(): Collection
    {
        return Membership::query()
            ->with('user')
            ->where('company_id', $this->currentCompany->id())
            ->get()
            ->sort(fn (Membership $a, Membership $b): int => [$a->role->rank(), mb_strtolower($a->user->name)] <=> [$b->role->rank(), mb_strtolower($b->user->name)])
            ->values();
    }

    /** A membership of the current company; any other id is a 404. */
    public function find(string $id): Membership
    {
        return Membership::query()
            ->with('user')
            ->where('company_id', $this->currentCompany->id())
            ->whereKey($id)
            ->firstOrFail();
    }

    public function changeRole(Membership $membership, Role $role): Membership
    {
        return DB::transaction(function () use ($membership, $role): Membership {
            $membership = $this->lock($membership);

            if ($membership->role === $role) {
                return $membership;
            }

            $this->ensureMayManage($membership);
            self::ensureMayGrant($this->currentCompany, $role);

            if ($membership->role === Role::Owner && $this->owners() === 1) {
                throw ValidationException::withMessages([
                    'role' => 'The company needs at least one owner. Make another member an owner first.',
                ]);
            }

            $membership->fill(['role' => $role])->save();

            return $membership;
        });
    }

    public function remove(Membership $membership, User $actor): void
    {
        DB::transaction(function () use ($membership, $actor): void {
            $membership = $this->lock($membership);

            if ($membership->user_id === $actor->getKey()) {
                throw new ConflictHttpException('You cannot remove yourself from the company. Another owner or admin can do it.');
            }

            $this->ensureMayManage($membership);

            if ($membership->role === Role::Owner && $this->owners() === 1) {
                throw new ConflictHttpException('The company needs at least one owner, so its last owner cannot be removed.');
            }

            $membership->delete();
        });
    }

    /**
     * Only owners may grant the owner role: on a change of role (here) and on an invitation.
     */
    public static function ensureMayGrant(CurrentCompany $currentCompany, Role $role, string $field = 'role'): void
    {
        if ($role === Role::Owner && $currentCompany->role() !== Role::Owner) {
            throw ValidationException::withMessages([$field => 'Only an owner can make someone an owner.']);
        }
    }

    /** Locks the company, then reads the membership again, now that no other change can run. */
    private function lock(Membership $membership): Membership
    {
        Company::query()->whereKey($this->currentCompany->id())->lockForUpdate()->first(['id']);

        return Membership::query()
            ->with('user')
            ->where('company_id', $this->currentCompany->id())
            ->whereKey($membership->getKey())
            ->firstOrFail();
    }

    /** Only an owner changes or removes another owner. */
    private function ensureMayManage(Membership $membership): void
    {
        if ($membership->role === Role::Owner && $this->currentCompany->role() !== Role::Owner) {
            throw new AccessDeniedHttpException('Only an owner can change or remove an owner.');
        }
    }

    private function owners(): int
    {
        return Membership::query()
            ->where('company_id', $this->currentCompany->id())
            ->where('role', Role::Owner)
            ->count();
    }
}
