<?php

namespace App\Support\Observability;

/**
 * Masks secrets and personal data in the text a log line carries (its message, the message of its error, the
 * strings of its detail), as the last line of defence: the application logs internal ids only, and never
 * interpolates a password, a key, a token, an email or an identification number into a message. What looks like
 * one is replaced by its kind in brackets, and the text is cut to its limit.
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
        // Personal data: emails, numbers long enough to identify a person or a company (an identification
        // number, a NIT with its check digit, a phone), IPv4 addresses.
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[email]',
        '/(?<![\w.])\d{7,}(?:-\d)?(?!\w|\.\d)/' => '[number]',
        '/(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?!\w|\.\d)/' => '[ip]',
    ];

    /** A run of characters as long as a generated secret; one with letters and digits that is not a UUID is masked. */
    private const string LONG_TOKEN = '/[A-Za-z0-9+\/=_-]{32,}/';

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function text(string $value, int $limit): string
    {
        $value = mb_scrub($value, 'UTF-8');

        foreach (self::PATTERNS as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? '[unreadable]';
        }

        $value = preg_replace_callback(self::LONG_TOKEN, function (array $match): string {
            $token = $match[0];

            return preg_match(self::UUID, $token) !== 1 && preg_match('/\d/', $token) === 1 && preg_match('/[A-Za-z]/', $token) === 1 ? '[redacted]' : $token;
        }, $value) ?? '[unreadable]';

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_strlen($value) > $limit ? mb_substr($value, 0, max(1, $limit - 1)).'…' : $value;
    }
}
