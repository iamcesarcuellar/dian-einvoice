<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\CreateCompany;
use App\Modules\Identity\Http\Requests\CompanyRules;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Session sign-in for the first-party web app: Sanctum with an httpOnly session cookie and a CSRF token.
 * Call GET /sanctum/csrf-cookie first and send the XSRF-TOKEN cookie back in the X-XSRF-TOKEN header.
 */
final class AuthController
{
    /** One answer when the email or the NIT is already registered, so neither can be probed here. */
    public const string NOT_REGISTERED = 'identity::errors.not_registered';

    /**
     * Register a user together with their first company, which they own. An email or a NIT that
     * is already registered answers 422 with the same message for both, without naming the field.
     * Ten attempts per hour from each address.
     */
    public function register(RegisterRequest $request, CreateCompany $createCompany): JsonResponse
    {
        $this->ensureSession($request);

        $email = $request->safe()->string('email')->toString();
        /** @var array<string, mixed> $company */
        $company = array_intersect_key($request->safe()->array('company'), array_flip(CompanyRules::FIELDS));

        try {
            $user = DB::transaction(function () use ($request, $createCompany, $email, $company): User {
                if (User::query()->where('email', $email)->exists() || Company::query()->where('identification_number', $company['identification_number'] ?? null)->exists()) {
                    throw new UnprocessableEntityHttpException(__(self::NOT_REGISTERED));
                }

                $user = User::create($request->safe(['name', 'email', 'password']));
                $createCompany($user, $company);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Someone registered the same email or NIT at the same moment.
            throw new UnprocessableEntityHttpException(__(self::NOT_REGISTERED));
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return UserResource::make($user->load('companies'))->response()->setStatusCode(201);
    }

    /**
     * Sign in. Five failed attempts per minute are allowed for each email and IP address, and twenty
     * attempts per minute from each address. The session gets a new id.
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
     * Sign out: the session is invalidated, so its cookie stops working, and the CSRF token changes.
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
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($user->load('companies'));
    }

    private function ensureSession(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new BadRequestHttpException(__('identity::errors.session_only'));
        }
    }
}
