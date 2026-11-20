<?php

namespace App\Modules\Identity\Tenancy;

use App\Modules\Developers\Models\ApiKey;
use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
use Closure;
use Illuminate\Support\Facades\Context;
use LogicException;

/**
 * The company the current request acts on, and who acts: the signed-in user's membership in it, or
 * an API key of the company. Set by the ResolveCurrentCompany middleware and read by CompanyScope.
 * Lives for one request, or one queued job: jobs act on a company without a user, through runAs().
 */
final class CurrentCompany
{
    private ?Membership $membership = null;

    /** The API key of the request, when it signs in with one instead of a session. */
    private ?ApiKey $apiKey = null;

    /** The company a system process acts on, with no user and no role. */
    private ?Company $system = null;

    public function set(Membership $membership): void
    {
        $this->membership = $membership;
        $this->apiKey = null;
        $this->system = null;
        self::describe($membership->company_id, 'user:'.$membership->user_id);
    }

    /** A request signed with an API key acts on the company of the key, with the level of the key. */
    public function setApiKey(ApiKey $apiKey): void
    {
        $this->apiKey = $apiKey;
        $this->membership = null;
        $this->system = null;
        self::describe($apiKey->company_id, 'api-key:'.$apiKey->id);
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
        [$membership, $apiKey, $system] = [$this->membership, $this->apiKey, $this->system];
        [$companyBefore, $actorBefore] = [Context::get('company_id'), Context::get('actor')];
        $this->membership = null;
        $this->apiKey = null;
        $this->system = $company;
        self::describe($company->id, 'system');

        try {
            return $callback();
        } finally {
            $this->membership = $membership;
            $this->apiKey = $apiKey;
            $this->system = $system;
            self::describe(is_string($companyBefore) ? $companyBefore : null, is_string($actorBefore) ? $actorBefore : null);
        }
    }

    public function check(): bool
    {
        return $this->membership !== null || $this->apiKey !== null || $this->system !== null;
    }

    public function id(): string
    {
        return $this->system->id ?? $this->apiKey->company_id ?? $this->membership()->company_id;
    }

    public function company(): Company
    {
        return $this->system
            ?? $this->apiKey->company
            ?? $this->membership()->company
            ?? throw new LogicException('The company of the current request does not exist.');
    }

    /** The role of the signed-in user. API keys and the system have none. */
    public function role(): Role
    {
        return $this->membership()->role;
    }

    /** Whether whoever acts may do this: the role of the user, or the level of the API key. */
    public function can(Permission $permission): bool
    {
        if ($this->apiKey !== null) {
            return $this->apiKey->level->allows($permission);
        }

        return $this->role()->can($permission);
    }

    /** The API key the request signed with, or null for a session or the system. */
    public function apiKey(): ?ApiKey
    {
        return $this->apiKey;
    }

    /**
     * The company and who acts, in Laravel's Context, so every log line of the work names them (internal ids only:
     * docs/observability.md); null forgets them.
     */
    private static function describe(?string $companyId, ?string $actor): void
    {
        foreach (['company_id' => $companyId, 'actor' => $actor] as $key => $value) {
            if ($value === null) {
                Context::forget($key);
            } else {
                Context::add($key, $value);
            }
        }
    }

    private function membership(): Membership
    {
        return $this->membership
            ?? throw new LogicException('No current company: the request did not go through the company middleware.');
    }
}
