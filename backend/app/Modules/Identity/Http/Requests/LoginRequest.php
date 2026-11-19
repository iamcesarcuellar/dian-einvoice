<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

final class LoginRequest extends FormRequest
{
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Signs in with the web guard. Failed attempts are limited per email and IP address.
     */
    public function authenticate(): void
    {
        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException(
                "Too many sign-in attempts. Try again in {$seconds} seconds.",
                headers: ['Retry-After' => $seconds],
            );
        }

        // A failed attempt always takes the same time, so an unknown email (no hash to check) does not
        // answer sooner than a wrong password; a sign-in that works returns at once.
        $signedIn = (new Timebox)->call(function (Timebox $timebox): bool {
            $signedIn = Auth::guard('web')->attempt($this->only('email', 'password'));

            if ($signedIn) {
                $timebox->returnEarly();
            }

            return $signedIn;
        }, max(0, config()->integer('auth.login_timebox_microseconds', 400_000))) === true;

        if (! $signedIn) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }

    private function throttleKey(): string
    {
        return 'login:'.Str::transliterate($this->string('email')->lower()->value().'|'.$this->ip());
    }
}
