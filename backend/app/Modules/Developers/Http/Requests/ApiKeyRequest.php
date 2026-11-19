<?php

namespace App\Modules\Developers\Http\Requests;

use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Developers\Enums\ApiKeyLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * A new API key: a name, read_only or read_write, an optional last day of validity (Colombian
 * date; it works until the end of that day) and an optional limit of requests per minute.
 */
final class ApiKeyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'level' => ['required', Rule::enum(ApiKeyLevel::class)],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.Carbon::now(NumberTemplate::TIMEZONE)->toDateString()],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'expires_at' => 'expiration date',
            'rate_limit_per_minute' => 'requests per minute',
        ];
    }

    /** The end of the last day of validity, in Colombia, as an instant. */
    public function expiresAt(): ?Carbon
    {
        $date = $this->safe()->string('expires_at')->toString();

        return $date === '' ? null : Carbon::parse($date, NumberTemplate::TIMEZONE)->endOfDay()->utc();
    }
}
