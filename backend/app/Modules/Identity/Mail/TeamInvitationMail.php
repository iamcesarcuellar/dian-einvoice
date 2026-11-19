<?php

namespace App\Modules\Identity\Mail;

use App\Modules\Identity\Models\TeamInvitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email that invites someone to a company: who invites, the company, the role and until when
 * the link works. The link to the web app carries the token, which the database stores only as a
 * hash; the email is sent by the queued job SendTeamInvitation, whose payload is encrypted. The
 * invitation comes with its company and inviter loaded.
 */
final class TeamInvitationMail extends Mailable
{
    /** Dates are shown in Colombian time, as in the web app. */
    private const string TIMEZONE = 'America/Bogota';

    public function __construct(
        private readonly TeamInvitation $invitation,
        private readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        $replace = [
            'inviter' => (string) $this->invitation->inviter?->name,
            'company' => $this->invitation->company->name,
            'app' => (string) config('app.name'),
        ];

        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) config('mail.from.name')),
            subject: (string) __($this->invitation->inviter === null ? 'identity::invitation.subject_anonymous' : 'identity::invitation.subject', $replace),
        );
    }

    public function content(): Content
    {
        $role = $this->invitation->role->value;

        return new Content(
            view: 'identity::mail.invitation',
            text: 'identity::mail.invitation-text',
            with: [
                'app' => (string) config('app.name'),
                'company' => $this->invitation->company->name,
                'inviter' => $this->invitation->inviter?->name,
                'email' => $this->invitation->email,
                'role' => (string) __("identity::invitation.roles.{$role}"),
                'roleDescription' => (string) __("identity::invitation.role_descriptions.{$role}"),
                'expires' => $this->invitation->expires_at->copy()->setTimezone(self::TIMEZONE)->format('d/m/Y H:i'),
                'url' => rtrim((string) config('app.url'), '/').'/invitations/'.$this->token,
            ],
        );
    }
}
