<?php

namespace App\Modules\Dian\Pipeline;

use App\Modules\Billing\Enums\BalanceStatus;
use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Billing\Services\InvoiceBalance;
use App\Modules\Dian\Models\DianSetting;
use App\Modules\Dian\Models\DianTransmission;
use App\Modules\Dian\Services\DocumentStamp;
use App\Modules\Identity\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What a user can do on the chain of an issued document. Retry now: the next transmission attempt
 * right away while one is scheduled, or a new run from the step that failed. Resend, after a
 * rejection: a new run from the generation, with the CUFE (or the CUDE of a note) and the software
 * security code calculated again from the current settings, in case they were the problem; on a
 * later day the generation also moves the issue date to that day (FAD09e, IssueDateRefresh). A
 * credit note sent again must still fit what is left to credit on its invoice, checked with the
 * invoice locked, as when it was issued.
 */
final class ElectronicDocumentActions
{
    public function __construct(
        private readonly ElectronicDocumentPipeline $pipeline,
        private readonly DocumentStamp $stamp,
        private readonly InvoiceBalance $balances,
    ) {}

    public function retryNow(string $documentId, ?User $actor = null): Document
    {
        return DB::transaction(function () use ($documentId, $actor): Document {
            $document = $this->pipeline->lock($documentId) ?? throw new NotFoundHttpException;

            if ($document->electronic_status === ElectronicStatus::Sending && $document->electronic_next_attempt_at !== null) {
                $running = DianTransmission::query()
                    ->where('document_id', $document->getKey())
                    ->where('run', $document->electronic_run)
                    ->whereNull('finished_at')
                    ->exists();

                if ($running) {
                    throw new ConflictHttpException(__('dian::errors.attempt_running'));
                }

                $attempt = $document->electronic_attempt + 1;
                $document->fill(['electronic_next_attempt_at' => Carbon::now()])->save();
                $this->pipeline->timeline($document, DocumentEventType::RetryRequested, ['step' => 'transmit', 'waiting' => true, 'attempt' => $attempt], $actor);
                $this->pipeline->emit($document, ChainEvents::TRANSMIT, ['attempt' => $attempt]);

                return $document;
            }

            if ($document->electronic_status === ElectronicStatus::Failed) {
                $step = $document->electronic_failed_step ?? 'generate';
                [$state, $event, $payload] = match ($step) {
                    'transmit' => [ElectronicStatus::Signed, ChainEvents::TRANSMIT, ['attempt' => 1]],
                    'sign' => [ElectronicStatus::Generated, ChainEvents::SIGN, []],
                    default => [ElectronicStatus::Pending, ChainEvents::GENERATE, []],
                };

                $this->pipeline->move($document, $state, [
                    'electronic_run' => $document->electronic_run + 1,
                    'electronic_attempt' => 0,
                    'electronic_next_attempt_at' => null,
                    'electronic_failed_step' => null,
                ]);
                $this->pipeline->timeline($document, DocumentEventType::RetryRequested, ['step' => $step, 'waiting' => false, 'run' => $document->electronic_run], $actor);
                $this->pipeline->emit($document, $event, $payload);

                return $document;
            }

            throw new ConflictHttpException(__('dian::errors.retry_unavailable'));
        }, 3);
    }

    public function resend(string $documentId, ?User $actor = null): Document
    {
        return DB::transaction(function () use ($documentId, $actor): Document {
            $document = $this->pipeline->lock($documentId) ?? throw new NotFoundHttpException;

            if ($document->electronic_status !== ElectronicStatus::Rejected) {
                throw new ConflictHttpException(__('dian::errors.resend_unavailable'));
            }

            $settings = DianSetting::current();

            if (blank($settings->software_id) || blank($settings->software_pin)) {
                throw ValidationException::withMessages(['dian' => [__('dian::errors.resend_settings')]]);
            }

            if ($document->isNote()) {
                // The note is locked; its invoice after it, as on issue.
                $invoice = Document::query()->whereKey($document->related_document_id)->lockForUpdate()->firstOrFail();

                if ($invoice->balance_status === BalanceStatus::Voided) {
                    throw new ConflictHttpException(__('billing::errors.invoice_voided', ['number' => $invoice->full_number]));
                }

                $this->balances->assertFits($document, $invoice, BigDecimal::of((string) $document->payable_amount));
            }

            $numbering = $document->isNote() ? null : NumberTemplate::query()->findOrFail($document->number_template_id);
            $previousCufe = $document->cufe;

            // CUFE or CUDE, software security code, QR URL and software data, from the frozen copies
            // and the current settings and technical key.
            $document->fill($this->stamp->stamp($document, $numbering, $settings));

            $this->pipeline->move($document, ElectronicStatus::Pending, [
                'electronic_run' => $document->electronic_run + 1,
                'electronic_attempt' => 0,
                'electronic_next_attempt_at' => null,
                'electronic_failed_step' => null,
                'xml_path' => null,
                'xml_sha256' => null,
                'xml_size' => null,
                'xml_generated_at' => null,
                'xml_signed_at' => null,
                'signing_certificate_serial' => null,
                'dian_zip_file_name' => null,
                'dian_xml_file_name' => null,
                ...array_fill_keys(Document::DIAN_RESULT, null),
            ]);
            $this->pipeline->timeline($document, DocumentEventType::ResendRequested, [
                'run' => $document->electronic_run,
                'cufe_changed' => $previousCufe !== $document->cufe,
            ], $actor);
            $this->pipeline->emit($document, ChainEvents::GENERATE);

            return $document;
        }, 3);
    }
}
