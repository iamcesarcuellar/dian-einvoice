<?php

namespace App\Modules\Identity\Mail;

use App\Modules\Identity\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

/**
 * The email with the link to choose a new password: the web app page /reset-password/{token}
 * with the address in the query, as the password broker of the framework expects it back. The
 * database keeps only a hash of the token; the link works once, for the minutes of the broker.
 */
final class PasswordResetMail extends Mailable
{
    public function __construct(
        private readonly User $user,
        #[SensitiveParameter] private readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) config('mail.from.name')),
            subject: (string) __('identity::password_reset.subject', ['app' => config()->string('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'identity::mail.password-reset',
            text: 'identity::mail.password-reset-text',
            with: [
                'app' => config()->string('app.name'),
                'name' => $this->user->name,
                'email' => $this->user->email,
                'minutes' => self::minutes(),
                'url' => rtrim(config()->string('app.url'), '/').'/reset-password/'.$this->token.'?email='.rawurlencode($this->user->email),
            ],
        );
    }

    /** How long a link works: the expiry of the password broker of users. */
    public static function minutes(): int
    {
        return config()->integer('auth.passwords.users.expire', 60);
    }
}
