<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a company and makes the given user its owner.
 */
final class CreateCompany
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(User $owner, array $attributes): Company
    {
        return DB::transaction(function () use ($owner, $attributes): Company {
            $company = Company::create($attributes);

            Membership::create([
                'company_id' => $company->getKey(),
                'user_id' => $owner->getKey(),
                'role' => Role::Owner,
            ]);

            return $company;
        });
    }
}
