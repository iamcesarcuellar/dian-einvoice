<?php

namespace App\Modules\Billing\Models;

use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A withholding informed on a document, with its own copy of the rate.
 */
class DocumentWithholding extends Model
{
    use BelongsToCompany, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['position', 'withholding_id', 'dian_code', 'name', 'rate', 'base_amount', 'amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'rate' => 'decimal:4',
            'base_amount' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }
}
