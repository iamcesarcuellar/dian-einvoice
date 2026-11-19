<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\StoreTeamInvitationRequest;
use App\Modules\Identity\Http\Resources\TeamInvitationResource;
use App\Modules\Identity\Models\TeamInvitation;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Team\TeamInvitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Invitations to join the current company, for its owners and admins in the web app. The email
 * with the link is sent right away (here, to the local development mailbox); meta.email_sent says
 * whether the mail server took it, and an invitation whose email failed can be resent.
 */
final class TeamInvitationController
{
    /**
     * Pending and expired invitations, newest first; accepted and canceled ones are not listed.
     */
    public function index(TeamInvitations $invitations): AnonymousResourceCollection
    {
        return TeamInvitationResource::collection($invitations->open());
    }

    /**
     * Invite an email address with a role; the link works for seven days. An address that belongs
     * to a member or has a pending invitation answers 422 on email; only owners invite owners.
     */
    public function store(StoreTeamInvitationRequest $request, TeamInvitations $invitations): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        [$invitation, $sent] = $invitations->invite((string) $request->validated('email'), $request->role(), $user);

        return TeamInvitationResource::make($invitation)
            ->additional(['meta' => ['email_sent' => $sent]])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Send a pending or expired invitation again, with a new link valid for seven more days; the
     * earlier link stops working. Accepted or canceled invitations answer 409.
     */
    public function resend(TeamInvitation $invitation, TeamInvitations $invitations): JsonResponse
    {
        [$invitation, $sent] = $invitations->resend($invitation);

        return TeamInvitationResource::make($invitation)
            ->additional(['meta' => ['email_sent' => $sent]])
            ->response();
    }

    /**
     * Cancel an invitation: its link stops working. An accepted invitation answers 409.
     */
    public function destroy(TeamInvitation $invitation, TeamInvitations $invitations): Response
    {
        $invitations->cancel($invitation);

        return response()->noContent();
    }
}
