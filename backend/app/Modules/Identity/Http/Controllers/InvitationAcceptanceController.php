<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Enums\InvitationStatus;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Team\TeamInvitations;
use App\Support\Translation;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use LogicException;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page an invitation email links to, in the web app: what the invitation offers, and its
 * acceptance. Public routes without a current company, limited per address: the token of the link is the
 * credential. An address that already has an account accepts signed in as that account; an
 * address without one creates its account here and is signed in.
 */
final class InvitationAcceptanceController
{
    /**
     * The invitation of a link: the company, the role, who sent it and its status (pending, expired,
     * canceled or accepted). Only a pending invitation also shows its address and whether it already
     * has an account: an old or used link tells nothing about the person. A token that no
     * invitation has, a replaced one included, is a 404.
     */
    public function show(#[SensitiveParameter] string $token, TeamInvitations $invitations): JsonResponse
    {
        $invitation = $invitations->findByToken($token) ?? throw new NotFoundHttpException;
        // Deleting a company deletes its invitations (foreign keys cascade).
        $company = $invitation->company ?? throw new LogicException("Invitation {$invitation->id} has no company.");
        $pending = $invitation->status() === InvitationStatus::Pending;

        return response()->json(['data' => [
            'email' => $pending ? $invitation->email : null,
            'role' => $invitation->role,
            'status' => $invitation->status(),
            'expires_at' => $invitation->expires_at,
            'company' => ['id' => $company->id, 'name' => $company->name],
            'invited_by' => $invitation->inviter?->name,
            'existing_user' => $pending ? $invitations->hasAccount($invitation) : null,
        ]]);
    }

    /**
     * Accept a pending invitation and join its company with its role. With an account, sign in as
     * that account first (401 when signed out, 403 when signed in as someone else). Without one,
     * send name, password and password_confirmation: the account is created and signed in. An
     * expired, canceled or accepted invitation answers 409. Answers the signed-in user with their
     * companies, and meta.company_id, the company just joined.
     */
    public function accept(Request $request, #[SensitiveParameter] string $token, TeamInvitations $invitations): JsonResponse
    {
        if (! $request->hasSession()) {
            throw new BadRequestHttpException(__('identity::errors.invitations.web_app_only'));
        }

        $invitation = $invitations->findByToken($token) ?? throw new NotFoundHttpException;
        $invitations->ensurePending($invitation);

        if ($invitations->hasAccount($invitation)) {
            $user = Auth::guard('web')->user();

            if (! $user instanceof User) {
                throw new AuthenticationException(__('identity::errors.invitations.sign_in_as', ['email' => $invitation->email]));
            }

            if (Str::lower($user->email) !== $invitation->email) {
                throw new AccessDeniedHttpException(__('identity::errors.invitations.other_user', ['email' => $invitation->email]));
            }

            $invitations->acceptAs($invitation, $user);
        } else {
            /** @var array{name: string, password: string} $account */
            $account = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'password' => ['required', 'string', 'confirmed', Password::defaults()],
            ], [], Translation::lines('identity::validation.attributes.invitation'));

            $user = $invitations->acceptAsNewUser($invitation, $account['name'], $account['password']);

            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return UserResource::make($user->load('companies'))
            ->additional(['meta' => ['company_id' => $invitation->company_id]])
            ->response();
    }
}
