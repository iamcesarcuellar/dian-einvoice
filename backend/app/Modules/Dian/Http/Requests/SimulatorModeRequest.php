<?php

namespace App\Modules\Dian\Http\Requests;

use App\Modules\Dian\Simulator\DianSimulator;
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
        return [
            'delay_ms' => 'delay',
            'failure_rate' => 'failure percentage',
            'count' => 'number of requests',
        ];
    }

    /**
     * Only the settings of the chosen mode.
     *
     * @return array{mode: string, rule: string|null, delay_ms: int|null, failure_rate: int|null, count: int|null}
     */
    public function mode(): array
    {
        $mode = (string) $this->validated('mode');

        return [
            'mode' => $mode,
            'rule' => $mode === 'reject' ? (string) $this->validated('rule') : null,
            'delay_ms' => $mode === 'slow' ? (int) $this->validated('delay_ms') : null,
            'failure_rate' => $mode === 'intermittent' ? (int) $this->validated('failure_rate') : null,
            'count' => $mode === 'normal' || $this->validated('count') === null ? null : (int) $this->validated('count'),
        ];
    }
}
