<?php

namespace App\Modules\Dian\Http\Requests;

use App\Support\Translation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload of a signing certificate: a .p12 or .pfx file and the password that opens it. The
 * password is only used to check and store the bundle; it never comes back.
 */
final class SigningCertificateRequest extends FormRequest
{
    /** Maximum size of the file, in kilobytes; certificate bundles take a few. */
    private const int MAX_KILOBYTES = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:p12,pfx', 'max:'.self::MAX_KILOBYTES],
            'password' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return Translation::lines('dian::validation.attributes.certificate');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return Translation::lines('dian::validation.messages.certificate');
    }
}
