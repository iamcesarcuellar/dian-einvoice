<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Identity\Support\NitCheckDigit;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use App\Modules\Reference\Enums\IdentificationType;
use App\Modules\Reference\Enums\OrganizationType;
use App\Modules\Reference\Enums\TaxScheme;
use App\Modules\Reference\Models\Municipality;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer or supplier of the company. Every company has one system contact, the final
 * consumer, which cannot be edited or deleted.
 *
 * @property int|null $check_digit
 * @property list<string> $fiscal_responsibilities
 */
class Contact extends Model
{
    use BelongsToCompany, HasUlids;

    /** Identification number of the final consumer. */
    public const string FINAL_CONSUMER_NUMBER = '222222222222';

    public const string COLOMBIA = 'CO';

    /** @var list<string> */
    protected $fillable = [
        'organization_type',
        'identification_type',
        'identification_number',
        'check_digit',
        'legal_name',
        'trade_name',
        'tax_scheme',
        'fiscal_responsibilities',
        'email',
        'phone',
        'address',
        'country_code',
        'municipality_code',
        'postal_code',
        'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'country_code' => self::COLOMBIA,
        'is_active' => true,
        'is_system' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_type' => OrganizationType::class,
            'identification_type' => IdentificationType::class,
            'check_digit' => 'integer',
            'tax_scheme' => TaxScheme::class,
            'fiscal_responsibilities' => 'array',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Contact $contact): void {
            // Only a NIT has a check digit, always computed here with the DIAN algorithm.
            $contact->check_digit = $contact->identification_type === IdentificationType::Nit
                ? NitCheckDigit::calculate($contact->identification_number)
                : null;

            // A contact outside Colombia has no municipality.
            if ($contact->country_code !== self::COLOMBIA) {
                $contact->municipality_code = null;
            }
        });
    }

    /**
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'municipality_code', 'code');
    }
}
