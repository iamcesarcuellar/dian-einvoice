<?php

namespace App\Modules\Developers\Http\Requests;

use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Support\Translation;
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
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return Translation::lines('developers::validation.attributes.receiver');
    }

    /**
     * @return array{mode: string, count: int|null, delay_ms: int|null}
     */
    public function mode(): array
    {
        $input = $this->safe();
        $mode = $input->string('mode')->toString();

        return [
            'mode' => $mode,
            'count' => $mode === 'normal' || ! $input->filled('count') ? null : $input->integer('count'),
            'delay_ms' => $mode === 'slow' ? ($input->filled('delay_ms') ? $input->integer('delay_ms') : 7000) : null,
        ];
    }
}
