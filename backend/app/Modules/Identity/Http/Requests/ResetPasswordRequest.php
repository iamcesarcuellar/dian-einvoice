<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * The token of the link, the address it was sent to and the new password, which follows the same
 * policy as registration, invitations and the profile.
 */
final class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array{email: string, password: string, password_confirmation: string, token: string}
     */
    public function credentials(): array
    {
        return [
            'email' => $this->safe()->string('email')->toString(),
            'password' => $this->safe()->string('password')->toString(),
            'password_confirmation' => $this->string('password_confirmation')->toString(),
            'token' => $this->safe()->string('token')->toString(),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }
}
