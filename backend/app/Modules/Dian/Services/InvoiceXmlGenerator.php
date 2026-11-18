<?php

namespace App\Modules\Dian\Services;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\XmlStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Dian\Signing\XadesSigner;
use App\Modules\Dian\Ubl\InvoiceXml;
use App\Modules\Identity\Models\User;
use App\Support\Storage\DocumentStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Generates the UBL XML of an issued invoice, signs it with the company certificate (XAdES-EPES)
 * and stores only the signed XML, recording its path, SHA-256, size, signing time and the serial
 * number of the certificate, with "XML generated" and "XML signed" entries in the timeline. It
 * runs after the issue is committed, so a failure never undoes the issue: the invoice stays
 * issued, is marked as failed with the reason in its timeline, and can be regenerated.
 * Synchronous for now; stage 6 moves it to the queue.
 */
final class InvoiceXmlGenerator
{
    public function __construct(
        private readonly InvoiceXml $builder,
        private readonly XadesSigner $signer,
        private readonly SigningCertificates $certificates,
        private readonly DocumentStorage $storage,
    ) {}

    /**
     * @param  User|null  $actor  Who asked for it; null when it follows the issue.
     */
    public function generate(Document $document, ?User $actor = null): Document
    {
        try {
            $xml = $this->builder->build($document);
        } catch (Throwable $exception) {
            return $this->fail($document, $actor, [], 'Generation failed', $exception);
        }

        $generated = ['type' => DocumentEventType::XmlGenerated, 'payload' => null, 'at' => Carbon::now()];

        try {
            $certificate = $this->certificates->current()
                ?? throw new RuntimeException('The company has no signing certificate.');

            if (! $certificate->status()->usable()) {
                throw new RuntimeException("The signing certificate {$certificate->serial_number} is {$certificate->status()->value}.");
            }

            $signedAt = Carbon::now();
            $signed = $this->signer->sign($xml, $this->certificates->credentials($certificate), $signedAt);
        } catch (Throwable $exception) {
            return $this->fail($document, $actor, [$generated], 'Signing failed', $exception);
        }

        try {
            $stored = $this->storage->put($this->path($document), $signed);
        } catch (Throwable $exception) {
            return $this->fail($document, $actor, [$generated], 'Storage failed', $exception);
        }

        return $this->record($document, $actor, [
            'xml_status' => XmlStatus::Signed,
            'xml_path' => $stored->path,
            'xml_sha256' => $stored->sha256,
            'xml_size' => $stored->size,
            'xml_generated_at' => $generated['at'],
            'xml_signed_at' => $signedAt,
            'signing_certificate_serial' => $certificate->serial_number,
        ], [
            $generated,
            ['type' => DocumentEventType::XmlSigned, 'at' => $signedAt, 'payload' => [
                'certificate_serial' => $certificate->serial_number,
                'test_certificate' => $certificate->is_test,
                'sha256' => $stored->sha256,
                'size' => $stored->size,
            ]],
        ]);
    }

    /** Path in the document storage: company, document type and full number. */
    public function path(Document $document): string
    {
        return sprintf('%s/%s/%s.xml', $document->company_id, $document->type->value, $document->full_number);
    }

    /**
     * @param  list<array{type: DocumentEventType, payload: array<string, mixed>|null, at: Carbon}>  $events  What succeeded before the failure.
     */
    private function fail(Document $document, ?User $actor, array $events, string $stage, Throwable $exception): Document
    {
        report($exception);

        return $this->record($document, $actor, ['xml_status' => XmlStatus::Failed], [
            ...$events,
            ['type' => DocumentEventType::XmlFailed, 'at' => Carbon::now(), 'payload' => [
                'reason' => Str::limit("{$stage}: {$exception->getMessage()}", 500),
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{type: DocumentEventType, payload: array<string, mixed>|null, at: Carbon}>  $events
     */
    private function record(Document $document, ?User $actor, array $attributes, array $events): Document
    {
        DB::transaction(function () use ($document, $actor, $attributes, $events): void {
            $document->fill($attributes)->save();

            foreach ($events as $event) {
                DocumentEvent::create([
                    'document_id' => $document->getKey(),
                    'type' => $event['type'],
                    'payload' => $event['payload'],
                    'occurred_at' => $event['at'],
                    'actor_id' => $actor?->getKey(),
                ]);
            }
        });

        return $document;
    }
}
