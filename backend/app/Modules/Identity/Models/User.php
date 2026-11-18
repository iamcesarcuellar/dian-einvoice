<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\Role;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * Companies the user belongs to, with their municipality. Each one carries the membership,
     * with the role, as $company->membership.
     *
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'memberships')
            ->using(Membership::class)
            ->as('membership')
            ->withPivot('id', 'role')
            ->withTimestamps()
            ->with('municipality.department')
            ->orderBy('companies.name');
    }

    public function roleIn(Company $company): ?Role
    {
        return Membership::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $this->getKey())
            ->first()
            ?->role;
    }
}
