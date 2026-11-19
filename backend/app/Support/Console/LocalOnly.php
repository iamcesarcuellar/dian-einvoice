<?php

namespace App\Support\Console;

use LogicException;

/**
 * The development tools of the repository (make stress, chaos and the checks, the demo seeder) create
 * temporary companies, synthetic users and test certificates and talk to the local simulator and
 * mailbox: they run only where APP_ENV is local or testing, never against a real database.
 */
final class LocalOnly
{
    /** The environments where they run. */
    public const array ENVIRONMENTS = ['local', 'testing'];

    public static function allowed(): bool
    {
        return app()->environment(self::ENVIRONMENTS);
    }

    /** Why a tool refuses to run here, or null when it may. */
    public static function refusal(string $tool): ?string
    {
        return self::allowed() ? null : sprintf('%s is a local development tool: it runs only with APP_ENV local or testing, and APP_ENV is %s.', $tool, app()->environment());
    }

    /** For code without a console to answer on, such as a seeder. */
    public static function ensure(string $tool): void
    {
        if (($reason = self::refusal($tool)) !== null) {
            throw new LogicException($reason);
        }
    }
}
