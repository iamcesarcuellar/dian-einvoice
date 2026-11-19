<?php

namespace Tests\Integration\Identity;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Catalog\Models\Withholding;
use App\Modules\Developers\Auth\ApiKeys;
use App\Modules\Developers\Enums\ApiKeyLevel;
use App\Modules\Developers\Webhooks\WebhookEventTypes;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Membership;
use App\Modules\Payments\Services\RecordPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\IntegrationTestCase;

/**
 * Multi-tenancy: a record of another company is invisible, whatever the route and the method. The
 * test walks every route of the API that names a record, so a new route is covered from the start.
 */
final class TenantIsolationTest extends IntegrationTestCase
{
    /** Route parameters that are not records of a company. */
    private const array NOT_RECORDS = ['token', 'path'];

    public function test_every_record_of_another_company_answers_404(): void
    {
        $other = $this->company('Synthetic Other Company', 'OTR');
        $records = $this->recordsOf($other);
        $company = $this->company('Synthetic Own Company', 'OWN');
        $api = $this->signedIn($company->user, $company);
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $parameters = array_map(fn (mixed $name): string => $this->text($name), $route->parameterNames());

            if ($parameters === [] || array_intersect($parameters, self::NOT_RECORDS) !== [] || ! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $uri = $missing = $route->uri();

            foreach ($parameters as $parameter) {
                $key = $parameter === 'document' ? explode('/', substr($uri, strlen('api/v1/')))[0] : $parameter;
                $this->assertArrayHasKey($key, $records, "No record of the other company for {{$parameter}} in {$uri}: add one to recordsOf().");
                $uri = str_replace('{'.$parameter.'}', $records[$key], $uri);
                $missing = str_replace('{'.$parameter.'}', Str::lower((string) Str::ulid()), $missing);
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $status = $api->json($method, '/'.$uri, [])->status();
                // Reads and deletions find nothing. A change may check its body before it looks the
                // record up: then it answers as it does for a record that does not exist at all.
                $expected = in_array($method, ['GET', 'DELETE'], true) ? 404 : $api->json($method, '/'.$missing, [])->status();

                $this->assertSame($expected, $status, "{$method} /{$route->uri()} answered {$status} for a record of another company, {$expected} for none.");
                $this->assertContains($status, [404, 422], "{$method} /{$route->uri()} answered {$status} for a record of another company.");
                $checked++;
            }
        }

        $this->assertGreaterThan(50, $checked);
    }

    public function test_another_company_cannot_be_acted_on_by_header_or_by_api_key(): void
    {
        $other = $this->company('Synthetic Other Company', 'OTR');
        $company = $this->company('Synthetic Own Company', 'OWN');
        [, $key] = app(ApiKeys::class)->create('Synthetic key', ApiKeyLevel::ReadWrite);

        $this->signedIn($company->user, $company)
            ->withHeaders(['X-Company-Id' => $other->company->id])
            ->getJson('/api/v1/invoices')
            ->assertForbidden();

        $this->withHeaders(['Authorization' => "Bearer {$key}", 'X-Company-Id' => $other->company->id])
            ->getJson('/api/v1/invoices')
            ->assertForbidden();
    }

    /**
     * One record of each kind in the company, by route parameter; documents by the first segment
     * of their route.
     *
     * @return array<string, string>
     */
    private function recordsOf(TemporaryCompany $company): array
    {
        $invoice = $this->acceptedInvoice($company);
        $credit = $this->draftNote($company, DocumentType::CreditNote, $invoice, '10000');
        $debit = $this->draftNote($company, DocumentType::DebitNote, $invoice, '10000');
        $payment = app(RecordPayment::class)([
            'contact_id' => $this->text($invoice->contact_id),
            'payment_date' => Carbon::now('America/Bogota')->toDateString(),
            'payment_means_code' => '10',
            'allocations' => [['document_id' => $invoice->id, 'amount' => '1000.00']],
        ], $company->user);
        [$apiKey] = app(ApiKeys::class)->create('Synthetic key of the other company', ApiKeyLevel::ReadWrite);
        $owner = $this->signedIn($company->user, $company);
        $item = $owner->postJson('/api/v1/items', ['code' => 'SYN-1', 'name' => 'Synthetic item', 'type' => 'service', 'unit_code' => '94', 'unit_price' => '1000'])->assertCreated();
        $subscription = $owner->postJson('/api/v1/webhooks', [
            'url' => rtrim((string) config('developers.receiver.url'), '/').'/'.$company->company->id,
            'events' => [WebhookEventTypes::all()[0]],
        ])->assertCreated();
        $subscriptionId = $this->text($subscription->json('data.id'));
        $delivery = $owner->postJson("/api/v1/webhooks/{$subscriptionId}/test")->assertSuccessful();
        $invitation = $owner->postJson('/api/v1/team/invitations', ['email' => 'synthetic-invitee@example.com', 'role' => Role::Member->value])->assertCreated();
        $this->actingIn($company);

        return [
            'company' => $company->company->id,
            'contact' => $company->customer()->id,
            'item' => $this->text($item->json('data.id')),
            'tax' => $this->text($company->iva()),
            'withholding' => $this->text(Withholding::query()->value('id')),
            'numberTemplate' => $this->text(NumberTemplate::query()->where('document_type', DocumentType::Invoice)->value('id')),
            'invoices' => $invoice->id,
            'credit-notes' => $credit->id,
            'debit-notes' => $debit->id,
            'payment' => $payment->id,
            'apiKey' => $apiKey->id,
            'subscription' => $subscriptionId,
            'delivery' => $this->text($delivery->json('data.id')),
            'membership' => $this->text(Membership::query()->where('company_id', $company->company->id)->value('id')),
            'invitation' => $this->text($invitation->json('data.id')),
        ];
    }
}
