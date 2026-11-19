<?php

namespace Tests\Integration\Billing;

use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Services\IssueDocument;
use App\Modules\Dian\Models\DianSetting;
use App\Modules\Dian\Models\SigningCertificate;
use Tests\IntegrationTestCase;
use Tests\Support\Parallel;

/**
 * Issuing: the moment a draft becomes a numbered, final document. Gaps or repeats in the numbers,
 * or an issued document that still changes, are faults the DIAN does not forgive.
 */
final class IssueTest extends IntegrationTestCase
{
    public function test_missing_requirements_answer_422_and_consume_no_number(): void
    {
        $company = $this->company();
        $draft = $this->draftInvoice($company, lines: []);
        SigningCertificate::query()->delete();
        DianSetting::current()->forceFill(['software_pin' => null])->save();
        $company->company->forceFill(['email' => null])->save();

        $response = $this->signedIn($company->user, $company)->postJson("/api/v1/invoices/{$draft->id}/issue");

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonValidationErrors(['lines', 'dian.certificate', 'dian.software_pin', 'company.email']);
        $this->assertSame(DocumentStatus::Draft, $draft->refresh()->status);
        $this->assertNull($draft->number);
        $this->assertSame(1, $company->numbering()->next_number);
    }

    public function test_an_issued_document_cannot_be_issued_again_changed_or_deleted(): void
    {
        $company = $this->company();
        $draft = $this->draftInvoice($company);
        $api = $this->signedIn($company->user, $company);

        $api->postJson("/api/v1/invoices/{$draft->id}/issue")
            ->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.full_number', 'TST1');

        $api->postJson("/api/v1/invoices/{$draft->id}/issue")->assertStatus(409);
        $api->patchJson("/api/v1/invoices/{$draft->id}", ['notes' => 'Changed after issue', 'lines' => [['description' => 'Other', 'quantity' => '9', 'unit_price' => '1']]])->assertStatus(409);
        $api->deleteJson("/api/v1/invoices/{$draft->id}")->assertStatus(409);

        $issued = $draft->refresh();
        $this->assertSame('TST1', $issued->full_number);
        $this->assertSame('119000.00', (string) $issued->payable_amount);
        $this->assertNull($issued->notes);
        $this->assertSame(1, $issued->lines()->count());
        $this->assertSame(2, $company->numbering()->next_number);
    }

    public function test_simultaneous_issues_take_consecutive_numbers_without_gaps_or_repeats(): void
    {
        $company = $this->company();
        $drafts = array_map(fn (): string => $this->draftInvoice($company)->id, range(1, 12));

        $outcomes = Parallel::run(count($drafts), fn (int $index): ?int => app(IssueDocument::class)($drafts[$index], $company->user)->number);

        $this->assertSame([], array_values(array_filter(array_column($outcomes, 'error'))));
        $numbers = array_column($outcomes, 'value');
        sort($numbers);
        $this->assertSame(range(1, 12), $numbers);
        $this->assertSame(13, $company->numbering()->next_number);
        $this->assertSame(12, Document::query()->where('status', DocumentStatus::Issued)->distinct()->count('number'));
    }
}
