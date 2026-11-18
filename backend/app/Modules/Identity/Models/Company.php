<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Support\NitCheckDigit;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant. Every business record belongs to exactly one company.
 */
class Company extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'legal_name',
        'identification_number',
        'check_digit',
        'email',
        'phone',
        'address',
        'city',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_digit' => 'integer',
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
}
