<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry of the document timeline.
 */
class DocumentEvent extends Model
{
    use BelongsToCompany, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['document_id', 'type', 'payload', 'occurred_at', 'actor_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentEventType::class,
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
