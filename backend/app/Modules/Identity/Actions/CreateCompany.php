<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Events\CompanyCreated;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Models\User;
use App\Support\Language;
use Illuminate\Support\Facades\DB;

/**
 * Creates a company, makes the given user its owner and lets other modules add its defaults. Unless the attributes
 * name it, the language of its documents is the language of the request that creates it.
 */
final class CreateCompany
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(User $owner, array $attributes): Company
    {
        return DB::transaction(function () use ($owner, $attributes): Company {
            $company = Company::create([...$attributes, 'document_language' => $attributes['document_language'] ?? Language::current()->value]);

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
