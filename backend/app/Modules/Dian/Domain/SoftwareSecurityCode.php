<?php

namespace App\Modules\Dian\Domain;

/**
 * Software security code (annex 1.9, section 11.8): SHA-384, in lowercase hexadecimal, of the
 * software ID, its PIN and the full number of the document, concatenated. Pure PHP.
 */
final class SoftwareSecurityCode
{
    public static function calculate(string $softwareId, string $pin, string $documentNumber): string
    {
        return hash('sha384', $softwareId.$pin.$documentNumber);
    }
}
