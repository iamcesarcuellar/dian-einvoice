<?php

namespace App\Modules\Identity\Tenancy;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
use LogicException;

/**
 * The company the current request acts on, with the signed-in user's membership in it.
 * Set by the ResolveCurrentCompany middleware and read by CompanyScope. Lives for one request.
 */
final class CurrentCompany
{
    private ?Membership $membership = null;

    public function set(Membership $membership): void
    {
        $this->membership = $membership;
    }

    public function check(): bool
    {
        return $this->membership !== null;
    }

    public function id(): string
    {
        return $this->membership()->company_id;
    }

    public function company(): Company
    {
        return $this->membership()->company;
    }

    public function role(): Role
    {
        return $this->membership()->role;
    }

    private function membership(): Membership
    {
        return $this->membership
            ?? throw new LogicException('No current company: the request did not go through the company middleware.');
    }
}
