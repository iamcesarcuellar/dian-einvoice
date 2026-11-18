<?php

namespace App\Modules\Payments\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use App\Modules\Payments\Enums\PaymentEventType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An entry of the timeline of a payment. */
class PaymentEvent extends Model
{
    use BelongsToCompany, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['payment_id', 'type', 'payload', 'occurred_at', 'actor_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentEventType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
