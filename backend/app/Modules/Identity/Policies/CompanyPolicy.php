<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\Response;

final class CompanyPolicy
{
    /**
     * Owners and admins edit the company. Other members get a 403; non-members get a 404.
     */
    public function update(User $user, Company $company): Response
    {
        $role = $user->roleIn($company);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return $role->canManageCompany()
            ? Response::allow()
            : Response::deny('Only owners and admins can edit this company.');
    }
}
