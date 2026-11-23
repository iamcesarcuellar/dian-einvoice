<?php

namespace App\Modules\Dian\Http\Requests;

use App\Modules\Dian\Enums\DianEnvironment;
use App\Support\Translation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update of the DIAN settings. The PIN is write-only: leave it out, or send it empty, to
 * keep the stored one. The environment can only be 2, habilitación (test).
 */
final class DianSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'integer', Rule::enum(DianEnvironment::class)],
            'software_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'software_pin' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^\S+$/'],
            'test_set_id' => ['sometimes', 'nullable', 'string', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return Translation::lines('dian::validation.attributes.settings');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return Translation::lines('dian::validation.messages.settings');
    }

    /**
     * The fields to store. An empty PIN means "keep the stored one".
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = $this->safe(['environment', 'software_id', 'test_set_id']);

        if (filled($this->validated('software_pin'))) {
            $settings['software_pin'] = $this->validated('software_pin');
        }

        return $settings;
    }

    protected function prepareForValidation(): void
    {
        foreach (['software_id', 'test_set_id', 'software_pin'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key)) ?: null]);
            }
        }
    }
}
