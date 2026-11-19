<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Support\NitCheckDigit;
use App\Modules\Reference\Enums\OrganizationType;
use App\Modules\Reference\Enums\TaxScheme;
use App\Modules\Reference\Models\Municipality;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The tenant. Every business record belongs to exactly one company. Its country is always CO.
 *
 * @property int $check_digit
 * @property list<string>|null $fiscal_responsibilities
 */
class Company extends Model
{
    use HasUlids;

    public const string COUNTRY_CODE = 'CO';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'legal_name',
        'identification_number',
        'check_digit',
        'organization_type',
        'tax_scheme',
        'fiscal_responsibilities',
        'registration_number',
        'email',
        'phone',
        'address',
        'municipality_code',
        'postal_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_digit' => 'integer',
            'organization_type' => OrganizationType::class,
            'tax_scheme' => TaxScheme::class,
            'fiscal_responsibilities' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // The check digit is always derived from the NIT on the server. The legal name defaults to the trade name.
        static::saving(function (Company $company): void {
            $company->check_digit = NitCheckDigit::calculate($company->identification_number);

            if (blank($company->legal_name)) {
                $company->legal_name = $company->name;
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
