<?php

namespace App\Modules\Dian\Models;

use App\Modules\Billing\Models\Document;
use App\Modules\Dian\Transmission\TransmissionOutcome;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to the DIAN web service, which in this project is always the local simulator: the
 * operation of one attempt, with its duration and its result or error. It is written before the
 * call starts and completed when it ends, so a row without finished_at belongs to a worker that
 * stopped during the call.
 */
class DianTransmission extends Model
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
        'operation',
        'gateway',
        'endpoint',
        'zip_file_name',
        'started_at',
        'finished_at',
        'duration_ms',
        'outcome',
        'may_have_arrived',
        'http_status',
        'status_code',
        'status_description',
        'status_message',
        'is_valid',
        'messages',
        'response_path',
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
            'outcome' => TransmissionOutcome::class,
            'may_have_arrived' => 'boolean',
            'http_status' => 'integer',
            'is_valid' => 'boolean',
            'messages' => 'array',
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
