<?php

namespace App\Modules\Identity\Http\Requests;

use App\Support\Translation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class ForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return Translation::lines('identity::validation.attributes.login');
    }

    public function email(): string
    {
        return $this->safe()->string('email')->toString();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }
}
