<?php

namespace App\Modules\Dian\Http\Requests;

use App\Modules\Dian\Simulator\DianSimulator;
use App\Support\Translation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A failure mode of the local DIAN simulator for the requests of the current company.
 */
final class SimulatorModeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(DianSimulator::MODES)],
            'rule' => ['nullable', 'required_if:mode,reject', 'string', 'max:20'],
            'delay_ms' => ['nullable', 'required_if:mode,slow', 'integer', 'min:100', 'max:120000'],
            'failure_rate' => ['nullable', 'required_if:mode,intermittent', 'integer', 'min:1', 'max:100'],
            'count' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return Translation::lines('dian::validation.attributes.simulator');
    }

    /**
     * Only the settings of the chosen mode.
     *
     * @return array{mode: string, rule: string|null, delay_ms: int|null, failure_rate: int|null, count: int|null}
     */
    public function mode(): array
    {
        $input = $this->safe();
        $mode = $input->string('mode')->toString();

        return [
            'mode' => $mode,
            'rule' => $mode === 'reject' ? $input->string('rule')->toString() : null,
            'delay_ms' => $mode === 'slow' ? $input->integer('delay_ms') : null,
            'failure_rate' => $mode === 'intermittent' ? $input->integer('failure_rate') : null,
            'count' => $mode === 'normal' || ! $input->filled('count') ? null : $input->integer('count'),
        ];
    }
}
