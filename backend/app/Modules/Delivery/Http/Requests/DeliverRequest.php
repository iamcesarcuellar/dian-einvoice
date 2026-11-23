<?php

namespace App\Modules\Delivery\Http\Requests;

use App\Support\Translation;
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

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return Translation::lines('delivery::validation.attributes.deliver');
    }

    public function email(): ?string
    {
        $email = trim($this->safe()->string('email')->toString());

        return $email === '' ? null : $email;
    }
}
