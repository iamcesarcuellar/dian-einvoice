<?php

namespace App\Modules\Payments\Models;

use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The part of a payment applied to one invoice, which lives in Billing. */
class PaymentAllocation extends Model
{
    use BelongsToCompany, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['payment_id', 'document_id', 'document_number', 'document_issue_date', 'document_due_date', 'amount', 'balance_after'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_issue_date' => 'date',
            'document_due_date' => 'date',
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
