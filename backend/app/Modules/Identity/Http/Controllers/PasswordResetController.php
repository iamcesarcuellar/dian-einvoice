<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Passwords\SendPasswordResetLink;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Password recovery with the password broker of the framework: a link by email that works once,
 * for 60 minutes, and the new password. Neither answer says whether an address has an account.
 * Both are limited per address (IP); the broker also sends one link a minute per account.
 */
final class PasswordResetController
{
    /** The same answer, every time: a link was sent if the address has an account. */
    public const string LINK_SENT = 'identity::errors.link_sent';

    /** The same answer for an unknown address, a wrong, used or expired token. */
    public const string INVALID_LINK = 'identity::errors.invalid_link';

    /**
     * Ask for a link to choose a new password. Answers 202 with the same message whether or not
     * the address has an account; the email is sent from the queue.
     */
    public function sendLink(ForgotPasswordRequest $request): JsonResponse
    {
        SendPasswordResetLink::dispatch($request->email());

        return response()->json(['data' => ['message' => __(self::LINK_SENT)]], 202);
    }

    /**
     * Choose a new password with the token of the link and the address it was sent to. Every
     * session of the account ends, this one included: sign in again with the new password. A
     * wrong, used or expired link answers 422.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset($request->credentials(), function (User $user, #[SensitiveParameter] string $password): void {
            // The new hash ends every other session (Sanctum's AuthenticateSession compares it).
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw new UnprocessableEntityHttpException(__(self::INVALID_LINK));
        }

        return response()->json(['data' => ['message' => __('identity::errors.password_changed')]]);
    }
}
