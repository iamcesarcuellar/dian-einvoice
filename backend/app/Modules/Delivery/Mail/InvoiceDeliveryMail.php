<?php

namespace App\Modules\Delivery\Mail;

use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Support\ColombianFormat;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * The email that delivers an accepted invoice to its customer: the subject of annex 1.9 (section
 * 9.1), a short body in HTML and plain text, replies to the issuing company, and a single
 * attachment, the zip with the AttachedDocument and the PDF. Sent right away by the delivery step,
 * never queued on its own. It is read from the frozen copies of the document only.
 */
final class InvoiceDeliveryMail extends Mailable
{
    public function __construct(
        private readonly Document $document,
        private readonly string $subjectLine,
        private readonly string $package,
        private readonly string $packageName,
    ) {}

    public function envelope(): Envelope
    {
        $company = $this->document->company_snapshot;
        $name = (string) $company['legal_name'];
        $email = trim((string) ($company['email'] ?? ''));

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $name),
            replyTo: $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? [new Address($email, $name)] : [],
            subject: $this->subjectLine,
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'X-Document-Number' => (string) $this->document->full_number,
            'X-Document-Environment' => 'test',
        ]);
    }

    public function content(): Content
    {
        $company = $this->document->company_snapshot;

        return new Content(
            view: 'delivery::mail.invoice',
            text: 'delivery::mail.invoice-text',
            with: [
                'company' => (string) $company['legal_name'],
                'number' => (string) $this->document->full_number,
                'total' => ColombianFormat::money((string) $this->document->payable_amount),
                'dueDate' => $this->document->due_date === null ? null : ColombianFormat::date($this->document->due_date),
                'packageName' => $this->packageName,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->package, $this->packageName)->withMime('application/zip'),
        ];
    }
}
