<?php

namespace App\Support;

use LogicException;

/**
 * Groups of translation lines in the language of the application, such as the names of a request's fields for its
 * validation messages (attributes()) or its own messages (messages()).
 */
final class Translation
{
    /**
     * The lines of a group, each key with an optional prefix ("company." for a company nested in a request). A null
     * line is left out, so the framework's default stands for it: the field's key, its underscores as spaces.
     *
     * @return array<string, string>
     */
    public static function lines(string $key, string $prefix = ''): array
    {
        $lines = trans($key);

        if (! is_array($lines)) {
            throw new LogicException("The translation {$key} is not a group of lines.");
        }

        $named = [];

        foreach ($lines as $name => $line) {
            if (is_string($line)) {
                $named[$prefix.$name] = $line;
            }
        }

        return $named;
    }
}
