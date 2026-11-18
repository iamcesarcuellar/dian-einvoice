<?php

namespace App\Modules\Payments\Models;

use App\Modules\Catalog\Models\Contact;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Reference\Enums\PaymentMeans;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment received from one customer and applied to some of its accepted invoices: its amount is
 * the sum applied. It is numbered on record from the receipt numbering, and it is never edited or
 * deleted: it is voided, with a reason, and its invoices get their balance back.
 */
class Payment extends Model
{
    use BelongsToCompany, HasUlids;

    public const string CURRENCY = 'COP';

    public const array DETAILS = ['allocations', 'events.actor', 'events.apiKey', 'creator', 'voider'];

    /** @var list<string> */
    protected $fillable = [
        'id',
        'number_template_id',
        'prefix',
        'number',
        'full_number',
        'payment_date',
        'contact_id',
        'customer_name',
        'customer_identification',
        'amount',
        'currency',
        'payment_means_code',
        'reference',
        'notes',
        'status',
        'void_reason',
        'voided_at',
        'voided_by',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => self::CURRENCY,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'payment_means_code' => PaymentMeans::class,
            'status' => PaymentStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    public function isVoided(): bool
    {
        return $this->status === PaymentStatus::Voided;
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('document_number');
    }

    /**
     * @return HasMany<PaymentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
