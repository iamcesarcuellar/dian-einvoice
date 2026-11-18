<?php

namespace App\Modules\Dian\Transmission;

/**
 * The validation result in an answer of SendBillSync or GetStatus (annex 1.9, 7.10.3 and 7.11.3):
 * IsValid, StatusCode, StatusDescription, StatusMessage, the ErrorMessage strings split into rule,
 * kind and message, and the ApplicationResponse decoded from XmlBase64Bytes.
 */
final readonly class DianAnswer
{
    /** StatusCode of GetStatus for an unknown track ID: 66 (NSU) and 90 (TrackId) in the annex. */
    private const array NOT_FOUND = ['66', '90'];

    /**
     * @param  list<array{rule: string|null, kind: string, message: string}>  $messages  kind is rejection or notification.
     */
    public function __construct(
        public bool $isValid,
        public string $statusCode,
        public ?string $statusDescription,
        public ?string $statusMessage,
        public array $messages,
        public ?string $applicationResponse,
        public ?string $documentKey,
        public ?string $fileName,
    ) {}

    /** StatusCode without leading zeros kept as two digits: "0" and "00" are both "00". */
    public function code(): string
    {
        $code = ltrim($this->statusCode, '0');

        return str_pad($code, 2, '0', STR_PAD_LEFT);
    }

    public function notFound(): bool
    {
        return ! $this->isValid && in_array($this->code(), self::NOT_FOUND, true);
    }
}
