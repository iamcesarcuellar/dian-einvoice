<?php

namespace Tests\Integration\Identity;

use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\TeamInvitation;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Team\SendTeamInvitation;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\IntegrationTestCase;
use Tests\Support\WebBrowser;

/**
 * The link of an invitation is its only credential: a replaced link stops working at once, even in
 * an email still waiting in the queue; a used or expired one answers 409 and tells nothing about
 * the person; and an address that already has an account joins only signed in as that account.
 */
final class InvitationLinksTest extends IntegrationTestCase
{
    private const string PASSWORD = 'synthetic-Password-2026';

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'redis']);
    }

    public function test_a_replaced_link_stops_working_and_its_queued_email_is_not_sent(): void
    {
        $company = $this->company();
        $first = $this->invite($company, 'synthetic-resent@example.com');
        $invitation = TeamInvitation::query()->where('email', 'synthetic-resent@example.com')->firstOrFail();

        $this->signedIn($company->user, $company)->postJson("/api/v1/team/invitations/{$invitation->id}/resend")->assertOk();
        $second = $this->lastToken();
        $this->assertNotSame($first, $second);

        $visitor = WebBrowser::at($this);
        $visitor->json('GET', "/api/v1/invitations/{$first}")->assertNotFound();
        $visitor->json('POST', "/api/v1/invitations/{$first}/accept", $this->account())->assertNotFound();
        $visitor->json('GET', "/api/v1/invitations/{$second}")->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.email', 'synthetic-resent@example.com');

        // An email of the first link that the queue runs only now: not sent.
        $sent = $this->transport()->messages()->count();
        (new SendTeamInvitation($invitation->id, $first))->handle();
        $this->assertSame($sent, $this->transport()->messages()->count());
    }

    public function test_a_used_or_expired_link_answers_409_and_tells_nothing_about_the_person(): void
    {
        $company = $this->company();
        $used = $this->invite($company, 'synthetic-used@example.com');
        $expired = $this->invite($company, 'synthetic-expired@example.com');
        $visitor = WebBrowser::at($this);

        $visitor->json('POST', "/api/v1/invitations/{$used}/accept", $this->account())->assertCreated();
        WebBrowser::at($this)->json('POST', "/api/v1/invitations/{$used}/accept", $this->account())->assertStatus(409);

        Carbon::setTestNow(Carbon::now()->addDays(TeamInvitation::VALID_DAYS)->addMinute());

        try {
            $stranger = WebBrowser::at($this);
            $stranger->json('POST', "/api/v1/invitations/{$expired}/accept", $this->account())->assertStatus(409);

            foreach (['accepted' => $used, 'expired' => $expired] as $status => $token) {
                $stranger->json('GET', "/api/v1/invitations/{$token}")
                    ->assertOk()
                    ->assertJsonPath('data.status', $status)
                    ->assertJsonPath('data.email', null)
                    ->assertJsonPath('data.existing_user', null);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_an_address_with_an_account_joins_only_signed_in_as_that_account(): void
    {
        $company = $this->company();
        $invited = User::create(['name' => 'Synthetic Invited', 'email' => 'synthetic-existing@example.com', 'password' => self::PASSWORD]);
        $other = User::create(['name' => 'Synthetic Other', 'email' => 'synthetic-other@example.com', 'password' => self::PASSWORD]);
        $token = $this->invite($company, $invited->email, Role::Admin);

        $browser = WebBrowser::at($this);
        $browser->json('GET', "/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.existing_user', true);

        // Signed out, a new account for the address is not possible, nor joining.
        $browser->json('POST', "/api/v1/invitations/{$token}/accept", $this->account())->assertUnauthorized();

        $browser->json('POST', '/api/v1/auth/login', ['email' => $other->email, 'password' => self::PASSWORD])->assertOk();
        $browser->json('POST', "/api/v1/invitations/{$token}/accept")->assertForbidden();
        $this->assertNull($other->roleIn($company->company));

        $browser->json('POST', '/api/v1/auth/logout')->assertNoContent();
        $browser->json('POST', '/api/v1/auth/login', ['email' => $invited->email, 'password' => self::PASSWORD])->assertOk();
        $browser->json('POST', "/api/v1/invitations/{$token}/accept")->assertOk()->assertJsonPath('meta.company_id', $company->company->id);

        $this->assertSame(Role::Admin, $invited->roleIn($company->company));
        // The account keeps its own password.
        $this->assertTrue(password_verify(self::PASSWORD, $invited->refresh()->password));
    }

    /** Invites the address as the owner, and returns the token of the link emailed. */
    private function invite(TemporaryCompany $company, string $email, Role $role = Role::Member): string
    {
        $this->signedIn($company->user, $company)->postJson('/api/v1/team/invitations', ['email' => $email, 'role' => $role->value])->assertCreated();

        return $this->lastToken();
    }

    /**
     * @return array{name: string, password: string, password_confirmation: string}
     */
    private function account(): array
    {
        return ['name' => 'Synthetic Newcomer', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    private function lastToken(): string
    {
        $message = $this->transport()->messages()->last();
        $this->assertInstanceOf(SentMessage::class, $message);
        $email = $message->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame(1, preg_match('#/invitations/([A-Za-z0-9]+)#', (string) $email->getTextBody(), $match));

        return $match[1] ?? '';
    }

    private function transport(): ArrayTransport
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }
}
