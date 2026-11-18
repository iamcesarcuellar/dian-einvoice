<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Identity\Tenancy\BelongsToCompany;
use App\Modules\Reference\Enums\TaxType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A tax rate charged on items, such as IVA 19 %. System rates are company defaults: they can be
 * deactivated but never edited or deleted.
 */
class Tax extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = ['dian_code', 'name', 'rate', 'is_active'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'is_system' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dian_code' => TaxType::class,
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }
}
