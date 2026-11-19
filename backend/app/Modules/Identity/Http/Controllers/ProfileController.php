<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\UpdatePasswordRequest;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Response;

/**
 * The profile of the signed-in user: their name and their password. The email is the sign-in and
 * stays as it is.
 */
final class ProfileController
{
    /**
     * Change your name. Answers the signed-in user with their companies, as GET /auth/me.
     */
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $this->user($request);
        $user->update($request->validated());

        return UserResource::make($user->load('companies'));
    }

    /**
     * Change your password: the current one is asked again. The session gets a new id.
     */
    public function password(UpdatePasswordRequest $request): Response
    {
        $user = $this->user($request);
        $user->update(['password' => $request->safe()->string('password')->toString()]);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->noContent();
    }

    private function user(UpdateProfileRequest|UpdatePasswordRequest $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
