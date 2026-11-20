<?php

namespace App\Modules\Delivery\Mail;

use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Delivery\Enums\EmailOutcome;
use App\Modules\Delivery\Models\DeliveryAttempt;
use App\Modules\Delivery\Models\DocumentFile;
use App\Support\Observability\LogEvent;
use App\Support\Observability\Telemetry;
use App\Support\Storage\DocumentStorage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * Sends one email attempt through Laravel's mailer (SMTP to the local development mailbox in this
 * project) and says how it ended: sent with its Message-ID; failed when the server could not be
 * reached, timed out or answered a temporary error, which is retried; rejected when it refused the
 * email for good (5xx) or the address is not valid. It never throws. Each attempt writes one line
 * (delivery.email), never with the address, the subject or the attachment.
 */
final class DeliveryMailer
{
    public function __construct(private readonly DocumentStorage $storage) {}

    public function send(DeliveryAttempt $attempt): SendResult
    {
        $result = $this->attempt($attempt);

        Telemetry::record(
            LogEvent::DeliveryEmail,
            sprintf('The email of the document was %s.', $result->outcome->value),
            outcome: match ($result->outcome) {
                EmailOutcome::Sent => 'success',
                EmailOutcome::Rejected => 'rejected',
                default => 'failure',
            },
            durationMs: (float) $result->durationMs,
            documentId: $attempt->document_id,
            detail: ['result' => $result->outcome->value],
            level: $result->outcome === EmailOutcome::Sent ? 'info' : 'warning',
        );

        return $result;
    }

    private function attempt(DeliveryAttempt $attempt): SendResult
    {
        $started = hrtime(true);

        try {
            $document = Document::query()->findOrFail($attempt->document_id);
            $package = DocumentFile::query()
                ->where('document_id', $document->getKey())
                ->where('kind', DocumentFileKind::Package)
                ->firstOrFail();

            $mail = new InvoiceDeliveryMail($document, $attempt->subject, $this->storage->get($package->path), $package->file_name);
            $sent = Mail::mailer($attempt->mailer)->to($attempt->recipient)->send($mail);

            return new SendResult(EmailOutcome::Sent, $this->elapsed($started), messageId: $sent?->getMessageId());
        } catch (RfcComplianceException $exception) {
            return new SendResult(EmailOutcome::Rejected, $this->elapsed($started), error: $this->reason('The address is not valid', $exception));
        } catch (UnexpectedResponseException $exception) {
            $permanent = $exception->getCode() >= 500 && $exception->getCode() < 600;

            return new SendResult($permanent ? EmailOutcome::Rejected : EmailOutcome::Failed, $this->elapsed($started), error: $this->reason("The mail server answered {$exception->getCode()}", $exception));
        } catch (TransportExceptionInterface $exception) {
            return new SendResult(EmailOutcome::Failed, $this->elapsed($started), error: $this->reason('The mail server could not be reached', $exception));
        } catch (Throwable $exception) {
            report($exception);

            return new SendResult(EmailOutcome::Rejected, $this->elapsed($started), error: $this->reason('Unexpected error', $exception));
        }
    }

    private function reason(string $what, Throwable $exception): string
    {
        return Str::limit($what.': '.trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? ''), 480);
    }

    private function elapsed(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
