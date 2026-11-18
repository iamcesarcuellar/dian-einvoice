<?php

namespace App\Modules\Dian\Http\Requests;

use App\Modules\Dian\Enums\DianEnvironment;
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
        return [
            'software_id' => 'software ID',
            'software_pin' => 'software PIN',
            'test_set_id' => 'test set ID',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'environment.enum' => 'Only the test environment (2) is available. Production is not part of this project.',
            'software_id.uuid' => 'The software ID is the identifier the DIAN assigned, such as 56f2ae4e-9812-4fad-9255-08fcfcd5ccb0.',
            'test_set_id.uuid' => 'The test set ID is the identifier the DIAN assigned to the habilitación test set.',
            'software_pin.regex' => 'The software PIN cannot contain spaces.',
        ];
    }

    /**
     * The fields to store. An empty PIN means "keep the stored one".
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = $this->safe()->only(['environment', 'software_id', 'test_set_id']);

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
