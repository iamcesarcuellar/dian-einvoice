<?php

namespace App\Support\Observability;

/**
 * Masks secrets and personal data in the text a log line carries (its message, the message of its error, the
 * strings of its detail), as the last line of defence: the application logs internal ids only, and never
 * interpolates a password, a key, a token, an email or an identification number into a message. What looks like
 * one is replaced by its kind in brackets, and the text is cut to its limit. A UUID is an internal id, never
 * personal data: no rule that judges a value by its shape masks it, whole or in part, even when one of its blocks
 * is all digits. A value given to a key that names a secret (token=...) is still masked, whatever its shape.
 */
final class Redactor
{
    /** @var array<string, string> Pattern => replacement, in order. */
    private const array PATTERNS = [
        // Keys and certificates in PEM, whole or a header alone.
        '/-----BEGIN [A-Z0-9 ]+-----.*?(?:-----END [A-Z0-9 ]+-----|$)/s' => '[pem]',
        // The body of an XML document (UBL, a SOAP envelope): from its first tag on.
        '/<\?xml.*$/s' => '[document]',
        '/<(?:cbc|cac|ext|sts|ds|xades|soap|wcf|s):[A-Za-z].*$/s' => '[document]',
        '/<(?:Invoice|CreditNote|DebitNote|AttachedDocument|ApplicationResponse)\b.*$/s' => '[document]',
        // Credentials of this platform and of the usual services.
        '/\bdei_[A-Za-z0-9_]+/' => '[api-key]',
        '/\bwhsec_[A-Za-z0-9+\/=]+/' => '[secret]',
        '/\bbase64:[A-Za-z0-9+\/=]+/' => '[secret]',
        '/\bBearer\s+[^\s,;]+/i' => 'Bearer [token]',
        '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/' => '[token]',
        '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/' => '[aws-key]',
        // A value given to a key that names a secret: password=..., "token": "...", Authorization: ...
        '/\b(password|passwd|pwd|secret|token|api[_-]?key|authorization|cookie|pin|technical[_-]?key|app[_-]?key|signature)(["\']?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&]+)/i' => '$1$2[redacted]',
        // Personal data (emails come first, in EMAIL): numbers long enough to identify a person or a company (an
        // identification number, a NIT with its check digit, a phone), IPv4 addresses.
        '/(?<![\w.])\d{7,}(?:-\d)?(?!\w|\.\d)/' => '[number]',
        '/(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?!\w|\.\d)/' => '[ip]',
    ];

    /** An email address, masked before anything else, so that a UUID in one goes with it. */
    private const string EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    /** A run of characters as long as a generated secret; one with letters and digits is masked. */
    private const string LONG_TOKEN = '/[A-Za-z0-9+\/=_-]{32,}/';

    /** A UUID on its own, not part of a longer run of letters and digits. */
    private const string UUID = '/(?<![0-9A-Za-z])[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![0-9A-Za-z])/i';

    /** Where a UUID waits while the rules run: a private-use character, its index, and the character again. */
    private const string MARK = "\u{E000}";

    public static function text(string $value, int $limit): string
    {
        $value = str_replace(self::MARK, "\u{FFFD}", mb_scrub($value, 'UTF-8'));
        $value = preg_replace(self::EMAIL, '[email]', $value) ?? '[unreadable]';

        // Each UUID steps aside while the rules run, so that a block of digits in one is never taken for a number.
        $uuids = [];
        $value = preg_replace_callback(self::UUID, function (array $match) use (&$uuids): string {
            $uuids[] = $match[0];

            return self::MARK.(count($uuids) - 1).self::MARK;
        }, $value) ?? '[unreadable]';

        foreach (self::PATTERNS as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? '[unreadable]';
        }

        $value = preg_replace_callback(self::LONG_TOKEN, function (array $match): string {
            $token = $match[0];

            return preg_match('/\d/', $token) === 1 && preg_match('/[A-Za-z]/', $token) === 1 ? '[redacted]' : $token;
        }, $value) ?? '[unreadable]';

        // The UUIDs come back where they were; one inside a masked value is gone with it.
        $value = preg_replace_callback('/'.self::MARK.'(\d+)'.self::MARK.'/u', fn (array $match): string => $uuids[(int) $match[1]] ?? '', $value) ?? '[unreadable]';

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_strlen($value) > $limit ? mb_substr($value, 0, max(1, $limit - 1)).'…' : $value;
    }
}
