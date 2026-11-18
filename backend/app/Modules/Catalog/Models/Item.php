<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ItemType;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use App\Modules\Reference\Enums\UnitOfMeasure;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product or service the company sells. Without a tax it is excluded (no tax at all).
 */
class Item extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'unit_code',
        'unit_price',
        'tax_id',
        'standard_scheme',
        'standard_code',
        'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'unit_code' => UnitOfMeasure::class,
            'unit_price' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
