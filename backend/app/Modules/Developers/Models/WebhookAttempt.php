<?php

namespace App\Modules\Developers\Models;

use App\Modules\Developers\Enums\WebhookAttemptOutcome;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One HTTP call of a delivery, written before the call starts: its code, duration and the first
 * characters of the answer, or why it failed.
 */
class WebhookAttempt extends Model
{
    use BelongsToCompany, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['delivery_id', 'run', 'attempt', 'url', 'started_at', 'finished_at', 'duration_ms', 'status_code', 'outcome', 'error', 'response_snippet'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'run' => 'integer',
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'status_code' => 'integer',
            'outcome' => WebhookAttemptOutcome::class,
        ];
    }
}
