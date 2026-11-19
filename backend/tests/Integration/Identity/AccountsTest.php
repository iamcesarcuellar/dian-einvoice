<?php

namespace Tests\Integration\Identity;

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Models\User;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\IntegrationTestCase;
use Tests\Support\WebBrowser;

/**
 * Accounts as the web app uses them, over HTTP with real sessions in Redis and the cookies sent
 * back: sign-up without telling whether an email or a NIT exists, sign-in and sign-out with their
 * session ids, the attributes of the cookie, a password change that ends the other sessions, the
 * reset link (once, for 60 minutes, the same answer for any address), one password policy, and the
 * limits per address.
 */
final class AccountsTest extends IntegrationTestCase
{
    private const string PASSWORD = 'synthetic-Password-2026';

    protected function setUp(): void
    {
        parent::setUp();

        // Sessions that outlive a request, as in the stack.
        config(['session.driver' => 'redis']);
    }

    public function test_sign_up_answers_the_same_for_a_taken_email_or_nit_and_is_limited_per_address(): void
    {
        $nit = $this->nit();
        WebBrowser::at($this)->json('POST', '/api/v1/auth/register', $this->registration('synthetic-first@example.com', $nit))->assertCreated();

        $takenEmail = WebBrowser::at($this)->json('POST', '/api/v1/auth/register', $this->registration('synthetic-first@example.com', $this->nit()));
        $takenNit = WebBrowser::at($this)->json('POST', '/api/v1/auth/register', $this->registration('synthetic-second@example.com', $nit));

        foreach ([$takenEmail, $takenNit] as $response) {
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('detail', AuthController::NOT_REGISTERED)
                ->assertJsonMissingPath('errors');
        }

        $this->assertSame(1, User::query()->where('email', 'synthetic-first@example.com')->count());

        // Ten attempts an hour from one address, whatever they carry.
        $browser = WebBrowser::at($this);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $browser->json('POST', '/api/v1/auth/register', [])->assertStatus(422);
        }

        $browser->json('POST', '/api/v1/auth/register', $this->registration('synthetic-late@example.com', $this->nit()))
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After');
    }

    public function test_sign_in_regenerates_the_session_sign_out_invalidates_it_and_the_cookie_is_http_only(): void
    {
        $user = $this->user('synthetic-session@example.com');
        $browser = WebBrowser::at($this);

        $browser->json('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $cookie = config()->string('session.cookie');
        $before = $browser->cookie($cookie);
        $this->assertNotNull($before);

        $browser->json('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk()->assertJsonPath('data.email', $user->email);
        $signedIn = $browser->cookie($cookie);
        $this->assertNotSame($before, $signedIn, 'Signing in keeps the session id it started with.');

        $session = $browser->received[$cookie];
        $this->assertTrue($session->isHttpOnly());
        $this->assertSame('lax', $session->getSameSite());
        $this->assertFalse($session->isSecure(), 'Locally the web app runs on HTTP.');
        $browser->json('GET', '/api/v1/auth/me')->assertOk();

        // The session id from before signing in never becomes a signed-in session.
        (clone $browser)->withCookie($cookie, $before)->json('GET', '/api/v1/auth/me')->assertUnauthorized();

        $browser->json('POST', '/api/v1/auth/logout')->assertNoContent();
        $this->assertNotSame($signedIn, $browser->cookie($cookie));
        $browser->withCookie($cookie, $signedIn)->json('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_in_production_the_session_cookie_is_secure(): void
    {
        $previous = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null];
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'production';

        try {
            /** @var array{secure: bool, http_only: bool, same_site: string} $session */
            $session = require config_path('session.php');
        } finally {
            [$_SERVER['APP_ENV'], $_ENV['APP_ENV']] = $previous;
        }

        $this->assertTrue($session['secure']);
        $this->assertTrue($session['http_only']);
        $this->assertSame('lax', $session['same_site']);
    }

    public function test_failed_sign_ins_are_limited_per_email_and_per_address(): void
    {
        $user = $this->user('synthetic-limited@example.com');
        $browser = WebBrowser::at($this);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $browser->json('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertStatus(422);
        }

        // The right password too, once the email is limited from this address.
        $browser->json('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json');
        WebBrowser::at($this)->json('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();

        // Twenty attempts a minute from one address, whichever emails they try.
        $spraying = WebBrowser::at($this);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $spraying->json('POST', '/api/v1/auth/login', ['email' => "synthetic-spray-{$attempt}@example.com", 'password' => 'wrong-password'])->assertStatus(422);
        }

        $spraying->json('POST', '/api/v1/auth/login', ['email' => 'synthetic-spray-last@example.com', 'password' => 'wrong-password'])->assertStatus(429);
    }

    public function test_changing_the_password_ends_the_other_sessions(): void
    {
        $user = $this->user('synthetic-two-sessions@example.com');
        [$laptop, $phone] = [WebBrowser::at($this), WebBrowser::at($this)];

        foreach ([$laptop, $phone] as $browser) {
            $browser->json('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        }

        $laptop->json('PUT', '/api/v1/auth/password', ['current_password' => self::PASSWORD, 'password' => 'synthetic-Changed-2026', 'password_confirmation' => 'synthetic-Changed-2026'])->assertNoContent();

        $phone->json('GET', '/api/v1/auth/me')->assertUnauthorized();
        $laptop->json('GET', '/api/v1/auth/me')->assertOk();
    }

    public function test_the_reset_link_answers_the_same_for_any_address_works_once_and_expires(): void
    {
        $user = $this->user('synthetic-forgot@example.com');
        $session = WebBrowser::at($this);
        $session->json('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();

        $known = WebBrowser::at($this)->json('POST', '/api/v1/auth/forgot-password', ['email' => $user->email]);
        $unknown = WebBrowser::at($this)->json('POST', '/api/v1/auth/forgot-password', ['email' => 'synthetic-nobody@example.com']);

        foreach ([$known, $unknown] as $response) {
            $response->assertStatus(202)->assertExactJson(['data' => ['message' => PasswordResetController::LINK_SENT]]);
        }

        [$token, $to] = $this->resetLink();
        $this->assertSame($user->email, $to);
        $this->assertSame(0, $this->sentTo('synthetic-nobody@example.com'), 'An address without an account got an email.');

        $reset = fn (string $token, string $password) => WebBrowser::at($this)->json('POST', '/api/v1/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password]);

        [$chosen, $again] = [Str::random(24), Str::random(24)];
        $reset(Str::random(64), $chosen)->assertStatus(422)->assertJsonPath('detail', PasswordResetController::INVALID_LINK);
        $reset($token, 'Short-pass1')->assertStatus(422)->assertJsonPath('errors.password.0', fn (string $message): bool => str_contains($message, '12'));
        $reset($token, $chosen)->assertOk();
        $reset($token, $again)->assertStatus(422)->assertJsonPath('detail', PasswordResetController::INVALID_LINK);

        // The new password works, and the session from before ended.
        WebBrowser::at($this)->json('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => $chosen])->assertOk();
        $session->json('GET', '/api/v1/auth/me')->assertUnauthorized();

        // A link is good for 60 minutes.
        $late = $this->user('synthetic-late-reset@example.com');
        WebBrowser::at($this)->json('POST', '/api/v1/auth/forgot-password', ['email' => $late->email])->assertStatus(202);
        [$lateToken] = $this->resetLink();
        Carbon::setTestNow(Carbon::now()->addMinutes(61));

        try {
            WebBrowser::at($this)->json('POST', '/api/v1/auth/reset-password', ['token' => $lateToken, 'email' => $late->email, 'password' => 'synthetic-Reset-2026', 'password_confirmation' => 'synthetic-Reset-2026'])
                ->assertStatus(422)
                ->assertJsonPath('detail', PasswordResetController::INVALID_LINK);
        } finally {
            Carbon::setTestNow();
        }

        // Ten links an hour from one address.
        $browser = WebBrowser::at($this);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $browser->json('POST', '/api/v1/auth/forgot-password', ['email' => "synthetic-many-{$attempt}@example.com"])->assertStatus(202);
        }

        $browser->json('POST', '/api/v1/auth/forgot-password', ['email' => 'synthetic-many-last@example.com'])->assertStatus(429);
    }

    public function test_one_password_policy_for_sign_up_invitations_the_profile_and_the_reset(): void
    {
        $short = 'Short-pass1';
        $company = $this->company();
        $user = $this->user('synthetic-policy@example.com');
        $profile = WebBrowser::at($this);
        $profile->json('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();

        $this->signedIn($company->user, $company)->postJson('/api/v1/team/invitations', ['email' => 'synthetic-invited-policy@example.com', 'role' => 'member'])->assertCreated();
        $invitation = $this->invitationToken();

        $answers = [
            'sign-up' => WebBrowser::at($this)->json('POST', '/api/v1/auth/register', ['password' => $short] + $this->registration('synthetic-short@example.com', $this->nit())),
            'invitation' => WebBrowser::at($this)->json('POST', "/api/v1/invitations/{$invitation}/accept", ['name' => 'Synthetic Short', 'password' => $short, 'password_confirmation' => $short]),
            'profile' => $profile->json('PUT', '/api/v1/auth/password', ['current_password' => self::PASSWORD, 'password' => $short, 'password_confirmation' => $short]),
            'reset' => WebBrowser::at($this)->json('POST', '/api/v1/auth/reset-password', ['token' => Str::random(64), 'email' => $user->email, 'password' => $short, 'password_confirmation' => $short]),
        ];

        foreach ($answers as $where => $response) {
            $this->assertSame(422, $response->status(), "{$where}: {$response->getContent()}");
            $this->assertStringContainsString('12 characters', $this->text($response->json('errors.password.0')), $where);
        }
    }

    private function user(string $email): User
    {
        return User::create(['name' => 'Synthetic User', 'email' => $email, 'password' => self::PASSWORD]);
    }

    /** A synthetic NIT nobody registered in this run. */
    private function nit(): string
    {
        return '8'.random_int(10_000_000, 99_999_999);
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(string $email, string $nit): array
    {
        return [
            'name' => 'Synthetic Registrant',
            'email' => $email,
            'password' => self::PASSWORD,
            'company' => ['name' => 'Synthetic Registered Company', 'identification_number' => $nit],
        ];
    }

    /**
     * The token and the address of the last password reset email.
     *
     * @return array{0: string, 1: string}
     */
    private function resetLink(): array
    {
        $email = $this->lastEmail();
        $this->assertSame(1, preg_match('#/reset-password/([A-Za-z0-9]+)\?email=([^\s]+)#', (string) $email->getTextBody(), $match));

        return [$match[1] ?? '', rawurldecode($match[2] ?? '')];
    }

    /** The token of the link in the last invitation email the mailer sent. */
    private function invitationToken(): string
    {
        $this->assertSame(1, preg_match('#/invitations/([A-Za-z0-9]+)#', (string) $this->lastEmail()->getTextBody(), $match));

        return $match[1] ?? '';
    }

    private function lastEmail(): Email
    {
        $message = $this->transport()->messages()->last();
        $this->assertInstanceOf(SentMessage::class, $message);
        $email = $message->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    /** How many emails went to an address. */
    private function sentTo(string $address): int
    {
        $count = 0;

        foreach ($this->transport()->messages()->all() as $message) {
            $this->assertInstanceOf(SentMessage::class, $message);

            foreach ($message->getEnvelope()->getRecipients() as $recipient) {
                $count += $recipient->getAddress() === $address ? 1 : 0;
            }
        }

        return $count;
    }

    private function transport(): ArrayTransport
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }
}
