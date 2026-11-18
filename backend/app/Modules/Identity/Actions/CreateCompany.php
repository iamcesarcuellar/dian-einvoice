<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Events\CompanyCreated;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a company, makes the given user its owner and lets other modules add its defaults.
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

            event(new CompanyCreated($company));

            return $company;
        });
    }
}
