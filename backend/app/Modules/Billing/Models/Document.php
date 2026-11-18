<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\BalanceStatus;
use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\InvoicePaymentStatus;
use App\Modules\Billing\Enums\DeliveryStep;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Delivery\Models\DeliveryAttempt;
use App\Modules\Delivery\Models\DocumentFile;
use App\Modules\Dian\Models\DianTransmission;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use App\Modules\Reference\Enums\PaymentForm;
use App\Modules\Reference\Enums\PaymentMeans;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice, or a credit or debit note that corrects an accepted invoice. A draft is editable and
 * has no number; once issued it is numbered, its copies of the company, customer and numbering are
 * frozen, it gets its CUFE (an invoice) or CUDE (a note, kept in the same column) and it can no
 * longer change. Its UBL XML is built from those copies only, by the
 * electronic document chain (see ElectronicStatus), which also sends it to the DIAN: in this
 * project, always the local simulator. Once accepted, the customer delivery (see DeliveryStatus)
 * builds its AttachedDocument and PDF and emails them, always to the local development mailbox.
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

    /** Relations the API loads to show one document: lines, totals, timeline, transmissions and delivered files. */
    public const array DETAILS = ['lines.item', 'taxes', 'withholdings', 'events.actor', 'events.apiKey', 'contact.municipality.department', 'transmissions', 'files', 'relatedDocument', 'relatedNotes', 'payments'];

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
        'related_document_id',
        'reason_code',
        'reason_text',
        'balance_status',
        'related_snapshot',
        'paid_amount',
        'receivable_amount',
        'payment_status',
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
        'delivery_status',
        'delivery_step',
        'delivery_status_at',
        'delivery_run',
        'delivery_attempt',
        'delivery_next_attempt_at',
        'delivery_recipient',
        'delivered_at',
        'delivery_error',
        'delivery_file_base',
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
            'balance_status' => BalanceStatus::class,
            'related_snapshot' => 'array',
            'paid_amount' => 'decimal:2',
            'receivable_amount' => 'decimal:2',
            'payment_status' => InvoicePaymentStatus::class,
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
            'delivery_status' => DeliveryStatus::class,
            'delivery_step' => DeliveryStep::class,
            'delivery_status_at' => 'datetime',
            'delivery_run' => 'integer',
            'delivery_attempt' => 'integer',
            'delivery_next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isNote(): bool
    {
        return $this->type !== DocumentType::Invoice;
    }

    /** CUFE for an invoice, CUDE for a note (annex 1.9, sections 11.2 and 11.4). */
    public function keyName(): string
    {
        return $this->isNote() ? 'CUDE' : 'CUFE';
    }

    /**
     * The payments applied to an invoice, as Billing keeps them; voided ones are released.
     *
     * @return HasMany<DocumentPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(DocumentPayment::class)->orderBy('payment_date')->orderBy('applied_at');
    }

    /**
     * The invoice a note corrects.
     *
     * @return BelongsTo<Document, $this>
     */
    public function relatedDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_document_id');
    }

    /**
     * The credit and debit notes of an invoice, oldest first. Not named notes: that is the column
     * with the free text of the document.
     *
     * @return HasMany<Document, $this>
     */
    public function relatedNotes(): HasMany
    {
        return $this->hasMany(self::class, 'related_document_id')->orderBy('created_at')->orderBy('id');
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
     * Files delivered to the customer: AttachedDocument, PDF and the zip with both.
     *
     * @return HasMany<DocumentFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(DocumentFile::class);
    }

    /**
     * Email attempts to the customer, oldest first.
     *
     * @return HasMany<DeliveryAttempt, $this>
     */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class)->orderBy('started_at')->orderBy('id');
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
