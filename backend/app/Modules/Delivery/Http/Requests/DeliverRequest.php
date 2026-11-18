<?php

namespace App\Modules\Delivery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Send again: optionally to another address than the current recipient.
 */
final class DeliverRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255'],
        ];
    }

    public function email(): ?string
    {
        $email = trim((string) ($this->validated('email') ?? ''));

        return $email === '' ? null : $email;
    }
}
