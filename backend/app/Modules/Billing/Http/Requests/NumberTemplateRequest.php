<?php

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Models\NumberTemplate;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creates (POST) or updates (PATCH, partial) a numbering range. The technical key is write-only:
 * leave it out to keep the stored one.
 */
final class NumberTemplateRequest extends FormRequest
{
    private const int MAX_NUMBER = 9_999_999_999;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->template() === null ? ['required'] : ['sometimes', 'required'];

        return [
            'document_type' => [...$required, Rule::enum(NumberingType::class)],
            'prefix' => ['nullable', 'string', 'regex:/^[A-Z0-9]{1,4}$/'],
            'from_number' => [...$required, 'integer', 'min:0', 'max:'.self::MAX_NUMBER],
            'to_number' => [...$required, 'integer', 'min:0', 'max:'.self::MAX_NUMBER],
            'next_number' => [...$required, 'integer', 'min:0', 'max:'.self::MAX_NUMBER],
            'resolution_number' => ['nullable', 'string', 'max:30'],
            'resolution_date' => ['nullable', 'date_format:Y-m-d'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
            'technical_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{8,128}$/'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Rules across fields, checked on the values the range will have after this request.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $errors = $validator->errors();
            $type = NumberingType::from($this->resultingString('document_type'));
            $from = $this->resultingInt('from_number');
            $to = $this->resultingInt('to_number');
            $next = $this->resultingInt('next_number');

            if ($from > $to) {
                $errors->add('to_number', 'The last number must be greater than or equal to the first.');
            } elseif ($next < $from || $next > $to) {
                $errors->add('next_number', 'The next number must be within the range.');
            }

            $validFrom = $this->resulting('valid_from');
            $validTo = $this->resulting('valid_to');

            if (filled($validFrom) && filled($validTo) && $validFrom >= $validTo) {
                $errors->add('valid_to', 'The end of the validity must be after its start.');
            }

            $resolutionDate = $this->resulting('resolution_date');

            if (filled($resolutionDate) && filled($validFrom) && $resolutionDate > $validFrom) {
                $errors->add('resolution_date', 'The resolution cannot be dated after the start of its validity.');
            }

            if ($type->requiresResolution()) {
                foreach (['resolution_number' => 'resolution number', 'resolution_date' => 'date of the resolution', 'valid_from' => 'start of the validity', 'valid_to' => 'end of the validity'] as $key => $label) {
                    if (blank($this->resulting($key))) {
                        $errors->add($key, "The {$label} is required for invoices.");
                    }
                }

                if (! $this->hasTechnicalKey()) {
                    $errors->add('technical_key', 'The technical key is required for invoices.');
                }
            }

            if ($from <= $to && ($overlap = $this->overlappingTemplate($type, $from, $to)) !== null) {
                $errors->add('from_number', sprintf(
                    'This range overlaps the numbering %s%d to %d.',
                    $overlap->prefix ?? '',
                    $overlap->from_number,
                    $overlap->to_number,
                ));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prefix.regex' => 'The prefix has up to 4 letters or digits.',
            'technical_key.regex' => 'The technical key has between 8 and 128 letters or digits.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('prefix'))) {
            $this->merge(['prefix' => Str::upper(trim($this->input('prefix'))) ?: null]);
        }

        // A new range starts at its first number unless told otherwise.
        if ($this->template() === null && ! $this->has('next_number') && $this->has('from_number')) {
            $this->merge(['next_number' => $this->input('from_number')]);
        }
    }

    /** The value after this request as text; empty when it has none. */
    private function resultingString(string $key): string
    {
        $value = $this->resulting($key);

        return is_scalar($value) ? (string) $value : '';
    }

    /** The value after this request as a whole number; 0 when it has none. */
    private function resultingInt(string $key): int
    {
        $value = $this->resulting($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    /** The value after this request: the one sent, or else the stored one. */
    private function resulting(string $key): mixed
    {
        if ($this->exists($key)) {
            return $this->input($key);
        }

        $value = $this->template()?->getAttribute($key);

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => $value,
        };
    }

    private function hasTechnicalKey(): bool
    {
        return $this->exists('technical_key')
            ? filled($this->input('technical_key'))
            : filled($this->template()?->technical_key);
    }

    /** Another range of the same document type and prefix that shares numbers with this one. */
    private function overlappingTemplate(NumberingType $type, int $from, int $to): ?NumberTemplate
    {
        $prefix = $this->resulting('prefix');

        return NumberTemplate::query()
            ->where('document_type', $type)
            ->when(
                $prefix === null,
                fn ($query) => $query->whereNull('prefix'),
                fn ($query) => $query->where('prefix', $prefix),
            )
            ->when($this->template(), fn ($query, NumberTemplate $template) => $query->whereKeyNot($template->getKey()))
            ->where('from_number', '<=', $to)
            ->where('to_number', '>=', $from)
            ->first();
    }

    private function template(): ?NumberTemplate
    {
        return $this->route('numberTemplate');
    }
}
