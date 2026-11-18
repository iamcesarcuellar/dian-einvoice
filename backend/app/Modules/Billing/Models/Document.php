<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Dian\Models\DianTransmission;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use App\Modules\Reference\Enums\PaymentForm;
use App\Modules\Reference\Enums\PaymentMeans;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice now; credit and debit notes from stage 8. A draft is editable and has no number;
 * once issued it is numbered, its copies of the company, customer and numbering are frozen, it
 * gets its CUFE and it can no longer change. Its UBL XML is built from those copies only, by the
 * electronic document chain (see ElectronicStatus), which also sends it to the DIAN: in this
 * project, always the local simulator.
 */
class Document extends Model
{
    use BelongsToCompany, HasUlids;

    public const string CURRENCY = 'COP';

    /** Amount columns, written from the calculator. */
    public const array AMOUNTS = [
        'subtotal_amount',
        'discount_total',
        'line_extension_amount',
        'tax_exclusive_amount',
        'tax_total',
        'tax_inclusive_amount',
        'payable_amount',
        'withholding_total',
        'amount_due',
    ];

    /** The last answer of the DIAN web service (the local simulator), cleared on Resend. */
    public const array DIAN_RESULT = [
        'dian_gateway',
        'dian_status_code',
        'dian_status_description',
        'dian_status_message',
        'dian_messages',
        'dian_response_path',
        'dian_responded_at',
    ];

    /** @var list<string> */
    protected $fillable = [
        'type',
        'status',
        'number_template_id',
        'prefix',
        'number',
        'full_number',
        'contact_id',
        'issue_date',
        'issue_time',
        'issued_at',
        'due_date',
        'payment_form',
        'payment_means_code',
        'currency',
        'notes',
        ...self::AMOUNTS,
        'company_snapshot',
        'customer_snapshot',
        'numbering_snapshot',
        'cufe',
        'software_security_code',
        'qr_url',
        'dian_environment',
        'dian_snapshot',
        'electronic_status',
        'electronic_status_at',
        'electronic_run',
        'electronic_attempt',
        'electronic_next_attempt_at',
        'electronic_failed_step',
        'xml_path',
        'xml_sha256',
        'xml_size',
        'xml_generated_at',
        'xml_signed_at',
        'signing_certificate_serial',
        'dian_zip_file_name',
        'dian_xml_file_name',
        ...self::DIAN_RESULT,
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'currency' => self::CURRENCY,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'number' => 'integer',
            'issue_date' => 'date',
            'issued_at' => 'datetime',
            'due_date' => 'date',
            'payment_form' => PaymentForm::class,
            'payment_means_code' => PaymentMeans::class,
            ...array_fill_keys(self::AMOUNTS, 'decimal:2'),
            'company_snapshot' => 'array',
            'customer_snapshot' => 'array',
            'numbering_snapshot' => 'array',
            'dian_environment' => 'integer',
            'dian_snapshot' => 'array',
            'electronic_status' => ElectronicStatus::class,
            'electronic_status_at' => 'datetime',
            'electronic_run' => 'integer',
            'electronic_attempt' => 'integer',
            'electronic_next_attempt_at' => 'datetime',
            'xml_size' => 'integer',
            'xml_generated_at' => 'datetime',
            'xml_signed_at' => 'datetime',
            'dian_messages' => 'array',
            'dian_responded_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    /**
     * @return HasMany<DocumentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('position');
    }

    /**
     * @return HasMany<DocumentTax, $this>
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(DocumentTax::class)->orderBy('dian_code')->orderByDesc('rate');
    }

    /**
     * @return HasMany<DocumentWithholding, $this>
     */
    public function withholdings(): HasMany
    {
        return $this->hasMany(DocumentWithholding::class)->orderBy('position');
    }

    /**
     * @return HasMany<DocumentEvent, $this>
     */
    public function events(): HasMany
    {
        // Entries of the same second keep the order they were written in (ULIDs grow with time).
        return $this->hasMany(DocumentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * Calls to the DIAN web service (the local simulator), oldest first.
     *
     * @return HasMany<DianTransmission, $this>
     */
    public function transmissions(): HasMany
    {
        return $this->hasMany(DianTransmission::class)->orderBy('started_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<NumberTemplate, $this>
     */
    public function numberTemplate(): BelongsTo
    {
        return $this->belongsTo(NumberTemplate::class);
    }
}
