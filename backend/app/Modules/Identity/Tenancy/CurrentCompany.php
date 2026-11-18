<?php

namespace App\Modules\Identity\Tenancy;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
use Closure;
use LogicException;

/**
 * The company the current request acts on, with the signed-in user's membership in it. Set by the
 * ResolveCurrentCompany middleware and read by CompanyScope. Lives for one request, or one queued
 * job: jobs act on a company without a user, through runAs().
 */
final class CurrentCompany
{
    private ?Membership $membership = null;

    /** The company a system process acts on, with no user and no role. */
    private ?Company $system = null;

    public function set(Membership $membership): void
    {
        $this->membership = $membership;
        $this->system = null;
    }

    /**
     * Runs the callback acting on the company as the system (a queued job, the relay), then
     * restores whatever was current before.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Company $company, Closure $callback): mixed
    {
        [$membership, $system] = [$this->membership, $this->system];
        $this->membership = null;
        $this->system = $company;

        try {
            return $callback();
        } finally {
            $this->membership = $membership;
            $this->system = $system;
        }
    }

    public function check(): bool
    {
        return $this->membership !== null || $this->system !== null;
    }

    public function id(): string
    {
        return $this->system?->getKey() ?? $this->membership()->company_id;
    }

    public function company(): Company
    {
        return $this->system ?? $this->membership()->company;
    }

    /** The role of the signed-in user. The system has none: it is never asked for permissions. */
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
