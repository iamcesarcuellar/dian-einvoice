<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\CreateCompany;
use App\Modules\Identity\Http\Requests\CompanyRules;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Session sign-in for the first-party web app: Sanctum with an httpOnly session cookie and a CSRF token.
 * Call GET /sanctum/csrf-cookie first and send the XSRF-TOKEN cookie back in the X-XSRF-TOKEN header.
 */
final class AuthController
{
    /**
     * Register a user together with their first company, which they own.
     */
    public function register(RegisterRequest $request, CreateCompany $createCompany): JsonResponse
    {
        $this->ensureSession($request);

        $user = DB::transaction(function () use ($request, $createCompany): User {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));

            $createCompany($user, Arr::only($request->validated('company'), CompanyRules::FIELDS));

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return UserResource::make($user->load('companies'))->response()->setStatusCode(201);
    }

    /**
     * Sign in. Five failed attempts per minute are allowed for each email and IP address.
     */
    public function login(LoginRequest $request): UserResource
    {
        $this->ensureSession($request);

        $request->authenticate();
        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return UserResource::make($user->load('companies'));
    }

    /**
     * Sign out and invalidate the session.
     */
    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * The signed-in user and their companies, with the role they hold in each one.
     */
    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user()->load('companies'));
    }

    private function ensureSession(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new BadRequestHttpException('Session sign-in is only available to the web app.');
        }
    }
}
