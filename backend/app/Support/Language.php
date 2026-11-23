<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The two languages of the platform: English, the default, and Spanish. A request picks one with Accept-Language
 * (App\Http\Middleware\NegotiateLanguage) for the messages of its response and the emails it sends; a company picks
 * one for its documents (companies.document_language): the PDF, the customer email and the texts its chain stores.
 */
enum Language: string
{
    case English = 'en';
    case Spanish = 'es';

    /**
     * The language a request prefers among the two, by the weights of its Accept-Language: es, es-CO or es-419 is
     * Spanish; anything else, or no header at all, is English.
     */
    public static function fromRequest(Request $request): self
    {
        return self::tryFrom((string) $request->getPreferredLanguage(array_column(self::cases(), 'value'))) ?? self::English;
    }

    /** The language the application speaks right now: the request's, or the one a job set. */
    public static function current(): self
    {
        return self::tryFrom(app()->getLocale()) ?? self::English;
    }

    /**
     * Runs the callback with this language as the application's, translations, dates and spelled-out numbers
     * included, and puts the previous language back afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($this->value);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
