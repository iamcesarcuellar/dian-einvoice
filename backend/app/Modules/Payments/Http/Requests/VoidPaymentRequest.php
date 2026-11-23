<?php

namespace App\Modules\Payments\Http\Requests;

use App\Support\Translation;
use Illuminate\Foundation\Http\FormRequest;

/** Why a payment is voided: it stays on the payment and on its timeline. */
final class VoidPaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return Translation::lines('payments::validation.attributes.void');
    }
}
