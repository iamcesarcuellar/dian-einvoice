<?php

namespace App\Modules\Developers\Models;

use App\Modules\Developers\Enums\WebhookDeliveryStatus;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One event for one subscription: pending while attempts remain, then succeeded or failed. A
 * redelivery by hand starts a new run with its own attempts; attempts are numbered across runs.
 */
class WebhookDelivery extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = ['subscription_id', 'event_id', 'event_type', 'status', 'run', 'attempts', 'run_attempts', 'next_attempt_at', 'last_attempt_at', 'last_status_code', 'last_error', 'succeeded_at', 'failed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebhookDeliveryStatus::class,
            'run' => 'integer',
            'attempts' => 'integer',
            'run_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'last_status_code' => 'integer',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    /**
     * @return BelongsTo<WebhookEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'event_id');
    }

    /**
     * @return HasMany<WebhookAttempt, $this>
     */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(WebhookAttempt::class, 'delivery_id')->orderBy('attempt')->orderBy('started_at');
    }
}
