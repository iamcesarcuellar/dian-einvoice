<?php

namespace App\Modules\Delivery\Models;

use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file delivered to the customer of a document, as stored: where, its SHA-256 and its size.
 */
class DocumentFile extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'document_id',
        'kind',
        'file_name',
        'mime_type',
        'path',
        'sha256',
        'size',
        'pages',
        'run',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DocumentFileKind::class,
            'size' => 'integer',
            'pages' => 'integer',
            'run' => 'integer',
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
