<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\UpdateTeamMemberRequest;
use App\Modules\Identity\Http\Resources\TeamMemberResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Team\TeamMembers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The members of the current company, for its owners and admins in the web app. A company always
 * keeps an owner; only owners grant the owner role or change and remove an owner; nobody removes
 * themselves here.
 */
final class TeamMemberController
{
    /**
     * Members with their roles and the date they joined: owners first, then admins, members and
     * viewers, each by name.
     */
    public function index(TeamMembers $members): AnonymousResourceCollection
    {
        return TeamMemberResource::collection($members->list());
    }

    /**
     * Change the role of a member. Demoting the last owner, or making someone an owner without
     * being one, answers 422 on role; an admin changing an owner answers 403.
     */
    public function update(UpdateTeamMemberRequest $request, string $membership, TeamMembers $members): TeamMemberResource
    {
        return TeamMemberResource::make($members->changeRole($members->find($membership), $request->role()));
    }

    /**
     * Remove a member from the company; their account stays. Removing yourself or the last owner
     * answers 409; an admin removing an owner answers 403.
     */
    public function destroy(Request $request, string $membership, TeamMembers $members): Response
    {
        /** @var User $user */
        $user = $request->user();

        $members->remove($members->find($membership), $user);

        return response()->noContent();
    }
}
