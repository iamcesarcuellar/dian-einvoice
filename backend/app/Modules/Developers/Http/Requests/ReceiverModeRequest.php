<?php

namespace App\Modules\Developers\Http\Requests;

use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A mode of the local webhook receiver for the next deliveries of the current company: normal,
 * error_500, or slow (it answers after delay_ms, 7000 by default, above the 5 second timeout).
 * count limits it to the next N deliveries; without it the mode stays until changed.
 */
final class ReceiverModeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(LocalWebhookReceiver::MODES)],
            'count' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'delay_ms' => ['nullable', 'integer', 'min:100', 'max:60000'],
        ];
    }

    /**
     * @return array{mode: string, count: int|null, delay_ms: int|null}
     */
    public function mode(): array
    {
        $mode = (string) $this->validated('mode');

        return [
            'mode' => $mode,
            'count' => $mode === 'normal' || $this->validated('count') === null ? null : (int) $this->validated('count'),
            'delay_ms' => $mode === 'slow' ? (int) ($this->validated('delay_ms') ?? 7000) : null,
        ];
    }
}
