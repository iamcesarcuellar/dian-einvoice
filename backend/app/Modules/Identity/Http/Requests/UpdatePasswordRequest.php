<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Support\Translation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * A new password, with the current one asked again so a session left open cannot change it.
 */
final class UpdatePasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return Translation::lines('identity::validation.attributes.password');
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $current = $this->input('current_password');
                $user = $this->user();

                if (is_string($current) && $user instanceof User && ! Hash::check($current, $user->password)) {
                    $validator->errors()->add('current_password', __('identity::validation.current_password'));
                }
            },
        ];
    }
}
