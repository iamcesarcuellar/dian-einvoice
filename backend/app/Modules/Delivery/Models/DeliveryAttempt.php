<?php

namespace App\Modules\Delivery\Models;

use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Enums\EmailOutcome;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to email the zip of a document to its customer, always caught by the local mailbox
 * in this project. Written before the mailer is called and completed when it returns, so a row
 * without finished_at belongs to a worker that stopped during the call.
 */
class DeliveryAttempt extends Model
{
    use BelongsToCompany, HasUlids;

    public $timestamps = false;

    /** started_at and finished_at keep milliseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /** @var list<string> */
    protected $fillable = [
        'document_id',
        'run',
        'attempt',
        'recipient',
        'subject',
        'package_file_name',
        'package_sha256',
        'mailer',
        'started_at',
        'finished_at',
        'duration_ms',
        'outcome',
        'message_id',
        'error',
    ];

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
            'outcome' => EmailOutcome::class,
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
