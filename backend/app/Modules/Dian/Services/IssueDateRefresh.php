<?php

namespace App\Modules\Dian\Services;

use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Dian\Models\DianSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Rules FAD09e, CAD09e and DAD09e of annex 1.9: the issue date of an invoice or note must be the
 * date of its signature. When the chain generates or signs the XML of a document the local DIAN
 * simulator has not accepted, on a calendar day in Colombia after its issue date (a Resend or a
 * Retry on another day, a chain that crosses midnight), the issue date and time become those of
 * that moment, and its CUFE or CUDE, software security code and QR URL are calculated again, as on
 * issue (DocumentStamp, with the current settings). The due date moves by the same number of days,
 * so the agreed term is kept; a cash document has no due date of its own: it is due on its issue
 * date, which just moved. The number does not change. An accepted document never changes: its
 * CUFE or CUDE is final only from then on.
 */
final class IssueDateRefresh
{
    public function __construct(private readonly DocumentStamp $stamp) {}

    /**
     * Moves the issue date and time of the locked document to $at when $at falls on a later day in
     * Colombia, and stamps it again.
     *
     * @return array<string, string|null>|null The previous and the new issue date, time, due date (the issue date of a cash document) and CUFE or CUDE, for the timeline; null when nothing changed.
     */
    public function refresh(Document $document, CarbonInterface $at): ?array
    {
        $local = $at->copy()->setTimezone(NumberTemplate::TIMEZONE);

        if ($document->issue_date === null || $local->toDateString() <= $document->issue_date->toDateString()) {
            return null;
        }

        if ($document->electronic_status === ElectronicStatus::Accepted) {
            throw new LogicException("{$document->full_number} is accepted: its issue date never changes.");
        }

        $previousIssueDate = $document->issue_date->toDateString();
        $previousDueDate = $document->due_date?->toDateString();
        $days = (int) Carbon::parse($previousIssueDate, NumberTemplate::TIMEZONE)->diffInDays(Carbon::parse($local->toDateString(), NumberTemplate::TIMEZONE));

        $previous = [
            'previous_issue_date' => $previousIssueDate,
            'previous_issue_time' => $document->issue_time,
            'previous_due_date' => $previousDueDate ?? $previousIssueDate,
            'previous_key' => $document->cufe,
        ];

        $document->fill([
            'issue_date' => $local->toDateString(),
            'issue_time' => $local->format('H:i:sP'),
            'due_date' => $previousDueDate === null ? null : Carbon::parse($previousDueDate, NumberTemplate::TIMEZONE)->addDays($days)->toDateString(),
        ]);

        $numbering = $document->isNote() ? null : NumberTemplate::query()->findOrFail($document->number_template_id);
        $document->fill($this->stamp->stamp($document, $numbering, DianSetting::current()))->save();

        return [
            ...$previous,
            'issue_date' => $document->issue_date->toDateString(),
            'issue_time' => $document->issue_time,
            'due_date' => $document->due_date?->toDateString() ?? $document->issue_date->toDateString(),
            'key' => $document->cufe,
        ];
    }
}
