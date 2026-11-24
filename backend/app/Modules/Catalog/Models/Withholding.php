<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Identity\Tenancy\BelongsToCompany;
use App\Modules\Reference\Enums\WithholdingBase;
use App\Modules\Reference\Enums\WithholdingType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A withholding rate, such as ReteFuente 4 %. Its base follows from its type: ReteIVA applies to
 * the IVA, ReteFuente and ReteICA to the subtotal before taxes, in the totals of each document.
 */
class Withholding extends Model
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
            'dian_code' => WithholdingType::class,
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function base(): WithholdingBase
    {
        return $this->dian_code->base();
    }
}
