<?php

namespace App\Modules\Identity\Passwords;

use App\Modules\Identity\Mail\PasswordResetMail;
use App\Modules\Identity\Models\User;
use App\Support\Language;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

/**
 * Asks the password broker of the framework for a reset link and emails it, from the queue, so the
 * answer to the request takes the same time whether or not the address has an account. The broker
 * stores a hash of a new token, valid for 60 minutes and used once, and gives at most one link a
 * minute per address; an address without an account gets nothing. The payload is only the address and the language
 * of the request that asked for the link, the language of the email.
 */
final class SendPasswordResetLink implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Attempts before the job is kept in failed_jobs; the SMTP connection has its own timeout. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 30;

    /** The language of the email: the one of the request that asked for the link (en or es). */
    public string $language = 'en';

    public function __construct(public readonly string $email, ?Language $language = null)
    {
        $this->language = ($language ?? Language::current())->value;
    }

    public function handle(): void
    {
        Password::broker()->sendResetLink(['email' => $this->email], function (User $user, #[SensitiveParameter] string $token): void {
            Mail::to($user->email)->locale($this->language)->send(new PasswordResetMail($user, $token));
        });
    }
}
