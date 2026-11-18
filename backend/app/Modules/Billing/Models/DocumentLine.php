<?php

namespace App\Modules\Billing\Models;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document line. It keeps its own copy of description, unit, price and tax, so later changes
 * to the item or the tax do not alter it.
 */
class DocumentLine extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'position',
        'item_id',
        'code',
        'description',
        'unit_code',
        'quantity',
        'unit_price',
        'discount_rate',
        'discount_amount',
        'line_extension_amount',
        'tax_id',
        'tax_code',
        'tax_name',
        'tax_rate',
        'tax_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:6',
            'discount_rate' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'line_extension_amount' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
