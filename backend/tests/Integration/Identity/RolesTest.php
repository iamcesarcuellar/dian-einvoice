<?php

namespace Tests\Integration\Identity;

use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\RecordPayment;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\IntegrationTestCase;

/**
 * @phpstan-import-type PaymentInput from RecordPayment
 *
 * Roles inside a company: viewers read, members work on documents and payments, owners and
 * admins manage everything, and only owners grant or touch the owner role.
 */
final class RolesTest extends IntegrationTestCase
{
    public function test_what_each_role_may_and_may_not_do(): void
    {
        $company = $this->company();
        $invoice = $this->acceptedInvoice($company);
        $users = array_combine(
            array_map(fn (Role $role): string => $role->value, Role::cases()),
            array_map(fn (Role $role): User => $role === Role::Owner ? $company->user : $this->member($company, $role), Role::cases()),
        );
        $payment = fn (): array => [
            'contact_id' => $this->text($invoice->contact_id),
            'payment_date' => Carbon::now('America/Bogota')->toDateString(),
            'payment_means_code' => '10',
            'amount' => '1000.00',
            'allocations' => [['document_id' => $invoice->id, 'amount' => '1000.00']],
        ];

        //                                                                   owner admin member viewer
        $rules = [
            'read invoices' => [fn () => ['GET', '/api/v1/invoices', []], [200, 200, 200, 200]],
            'create a draft' => [fn () => ['POST', '/api/v1/invoices', ['contact_id' => $invoice->contact_id]], [201, 201, 201, 403]],
            'record a payment' => [fn () => ['POST', '/api/v1/payments', $payment()], [201, 201, 201, 403]],
            'void a payment' => [fn () => ['POST', '/api/v1/payments/'.$this->payment($company, $payment()).'/void', ['reason' => 'Synthetic void of the role test']], [200, 200, 403, 403]],
            'read the DIAN settings' => [fn () => ['GET', '/api/v1/dian-settings', []], [200, 200, 403, 403]],
            'manage the team' => [fn () => ['GET', '/api/v1/team/members', []], [200, 200, 403, 403]],
            'edit the company' => [fn () => ['PATCH', '/api/v1/companies/'.$company->company->id, ['name' => 'Synthetic Renamed Company']], [200, 200, 403, 403]],
        ];

        foreach ($rules as $action => [$request, $expected]) {
            foreach (['owner', 'admin', 'member', 'viewer'] as $index => $role) {
                [$method, $uri, $body] = $request();
                $status = $this->signedIn($users[$role], $company)->json($method, $uri, $body)->status();

                $this->assertSame($expected[$index], $status, "{$role} may not {$action} as expected.");
            }
        }
    }

    public function test_only_owners_touch_the_owner_role_and_the_last_owner_stays(): void
    {
        $company = $this->company();
        $admin = $this->member($company, Role::Admin);
        $owner = Membership::query()->where('user_id', $company->user->id)->firstOrFail();
        $asAdmin = fn () => $this->signedIn($admin, $company);
        $asOwner = fn () => $this->signedIn($company->user, $company);

        $asAdmin()->postJson('/api/v1/team/invitations', ['email' => 'synthetic-owner@example.com', 'role' => 'owner'])->assertStatus(422);
        $asAdmin()->patchJson("/api/v1/team/members/{$owner->id}", ['role' => 'member'])->assertForbidden();
        $asAdmin()->deleteJson("/api/v1/team/members/{$owner->id}")->assertForbidden();

        // The only owner can neither step down nor leave.
        $asOwner()->patchJson("/api/v1/team/members/{$owner->id}", ['role' => 'admin'])->assertStatus(422);
        $asOwner()->deleteJson("/api/v1/team/members/{$owner->id}")->assertStatus(409);

        // An owner can make another member an owner; then the first one can step down.
        $adminMembership = Membership::query()->where('user_id', $admin->id)->firstOrFail();
        $asOwner()->patchJson("/api/v1/team/members/{$adminMembership->id}", ['role' => 'owner'])->assertOk();
        $asOwner()->patchJson("/api/v1/team/members/{$owner->id}", ['role' => 'admin'])->assertOk();

        $this->assertSame(Role::Owner, $adminMembership->refresh()->role);
        $this->assertSame(Role::Admin, $owner->refresh()->role);
    }

    public function test_an_invitation_is_accepted_with_a_new_account_once(): void
    {
        $company = $this->company();
        $this->signedIn($company->user, $company)
            ->postJson('/api/v1/team/invitations', ['email' => 'synthetic-new-member@example.com', 'role' => 'member'])
            ->assertCreated();
        $token = $this->invitationToken();
        Auth::forgetGuards();

        $this->getJson("/api/v1/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.existing_user', false);

        $web = ['Referer' => 'http://localhost:8601/invitations/'.$token, 'Accept' => 'application/json'];
        $this->withHeaders($web)->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Synthetic New Member', 'password' => 'synthetic-Password-2026', 'password_confirmation' => 'synthetic-Password-2026'])
            ->assertCreated()
            ->assertJsonPath('meta.company_id', $company->company->id);

        $user = User::query()->where('email', 'synthetic-new-member@example.com')->firstOrFail();
        $this->assertSame(Role::Member, $user->roleIn($company->company));
        $this->withHeaders($web)->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Synthetic Again', 'password' => 'synthetic-Password-2026', 'password_confirmation' => 'synthetic-Password-2026'])
            ->assertStatus(409);
    }

    /** The token of the link in the last invitation email the mailer sent. */
    private function invitationToken(): string
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $message = $transport->messages()->last();
        $this->assertInstanceOf(SentMessage::class, $message);
        $email = $message->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame(1, preg_match('#/invitations/([A-Za-z0-9]+)#', (string) $email->getTextBody(), $match));

        return $match[1] ?? '';
    }

    /**
     * A payment of the company, recorded by its owner, to be voided.
     *
     * @param  PaymentInput  $input
     */
    private function payment(TemporaryCompany $company, array $input): string
    {
        $this->actingIn($company);

        return app(RecordPayment::class)($input, $company->user)->id;
    }
}
