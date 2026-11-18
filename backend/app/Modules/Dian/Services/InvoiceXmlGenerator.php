<?php

namespace App\Modules\Dian\Services;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\XmlStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Dian\Ubl\InvoiceXml;
use App\Modules\Identity\Models\User;
use App\Support\Storage\DocumentStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generates the UBL XML of an issued invoice, stores it and records its path, SHA-256, size and
 * date on the document, with an "XML generated" entry in the timeline. It runs after the issue
 * is committed, so a failure never undoes the issue: the invoice stays issued, is marked as
 * failed with the reason in its timeline, and can be regenerated. Synchronous for now; stage 6
 * moves it to the queue.
 */
final class InvoiceXmlGenerator
{
    public function __construct(
        private readonly InvoiceXml $builder,
        private readonly DocumentStorage $storage,
    ) {}

    /**
     * @param  User|null  $actor  Who asked for it; null when it follows the issue.
     */
    public function generate(Document $document, ?User $actor = null): Document
    {
        try {
            $stored = $this->storage->put($this->path($document), $this->builder->build($document));
        } catch (Throwable $exception) {
            report($exception);

            return $this->record($document, $actor, [
                'xml_status' => XmlStatus::Failed,
            ], DocumentEventType::XmlFailed, [
                'reason' => Str::limit($exception->getMessage(), 500),
            ]);
        }

        return $this->record($document, $actor, [
            'xml_status' => XmlStatus::Generated,
            'xml_path' => $stored->path,
            'xml_sha256' => $stored->sha256,
            'xml_size' => $stored->size,
            'xml_generated_at' => Carbon::now(),
        ], DocumentEventType::XmlGenerated, [
            'sha256' => $stored->sha256,
            'size' => $stored->size,
        ]);
    }

    /** Path in the document storage: company, document type and full number. */
    public function path(Document $document): string
    {
        return sprintf('%s/%s/%s.xml', $document->company_id, $document->type->value, $document->full_number);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $payload
     */
    private function record(Document $document, ?User $actor, array $attributes, DocumentEventType $type, array $payload): Document
    {
        DB::transaction(function () use ($document, $actor, $attributes, $type, $payload): void {
            $document->fill($attributes)->save();

            DocumentEvent::create([
                'document_id' => $document->getKey(),
                'type' => $type,
                'payload' => $payload,
                'occurred_at' => Carbon::now(),
                'actor_id' => $actor?->getKey(),
            ]);
        });

        return $document;
    }
}
