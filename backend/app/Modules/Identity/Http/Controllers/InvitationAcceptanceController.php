<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Team\TeamInvitations;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page an invitation email links to, in the web app: what the invitation offers, and its
 * acceptance. Public routes without a current company and throttled: the token of the link is the
 * credential. An address that already has an account accepts signed in as that account; an
 * address without one creates its account here and is signed in.
 */
final class InvitationAcceptanceController
{
    /**
     * The invitation of a link: the company, the role, who sent it, its status (pending, expired,
     * canceled or accepted) and whether its address already has an account. An unknown token is a 404.
     */
    public function show(string $token, TeamInvitations $invitations): JsonResponse
    {
        $invitation = $invitations->findByToken($token) ?? throw new NotFoundHttpException;
        // Deleting a company deletes its invitations (foreign keys cascade).
        $company = $invitation->company ?? throw new LogicException("Invitation {$invitation->id} has no company.");

        return response()->json(['data' => [
            'email' => $invitation->email,
            'role' => $invitation->role,
            'status' => $invitation->status(),
            'expires_at' => $invitation->expires_at,
            'company' => ['id' => $company->id, 'name' => $company->name],
            'invited_by' => $invitation->inviter?->name,
            'existing_user' => $invitations->hasAccount($invitation),
        ]]);
    }

    /**
     * Accept a pending invitation and join its company with its role. With an account, sign in as
     * that account first (401 when signed out, 403 when signed in as someone else). Without one,
     * send name, password and password_confirmation: the account is created and signed in. An
     * expired, canceled or accepted invitation answers 409. Answers the signed-in user with their
     * companies, and meta.company_id, the company just joined.
     */
    public function accept(Request $request, string $token, TeamInvitations $invitations): JsonResponse
    {
        if (! $request->hasSession()) {
            throw new BadRequestHttpException('Invitations are accepted in the web app.');
        }

        $invitation = $invitations->findByToken($token) ?? throw new NotFoundHttpException;
        $invitations->ensurePending($invitation);

        if ($invitations->hasAccount($invitation)) {
            $user = Auth::guard('web')->user();

            if (! $user instanceof User) {
                throw new AuthenticationException("Sign in as {$invitation->email} to accept this invitation.");
            }

            if (Str::lower($user->email) !== $invitation->email) {
                throw new AccessDeniedHttpException("This invitation is for {$invitation->email}. Sign out, then sign in as {$invitation->email} to accept it.");
            }

            $invitations->acceptAs($invitation, $user);
        } else {
            /** @var array{name: string, password: string} $account */
            $account = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'password' => ['required', 'string', 'confirmed', Password::defaults()],
            ]);

            $user = $invitations->acceptAsNewUser($invitation, $account['name'], $account['password']);

            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return UserResource::make($user->load('companies'))
            ->additional(['meta' => ['company_id' => $invitation->company_id]])
            ->response();
    }
}
