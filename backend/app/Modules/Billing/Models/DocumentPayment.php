<?php

namespace App\Modules\Billing\Models;

use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment applied to an invoice, as the invoice keeps it: written by the payments module through
 * the invoice ledger, never read from the tables of Payments. Released when the payment is voided.
 */
class DocumentPayment extends Model
{
    use BelongsToCompany, HasUlids;

    public const string APPLIED = 'applied';

    public const string RELEASED = 'released';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'document_id',
        'payment_id',
        'payment_number',
        'payment_date',
        'payment_means_code',
        'amount',
        'status',
        'applied_at',
        'released_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'applied_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
