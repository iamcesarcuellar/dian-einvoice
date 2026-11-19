<?php

namespace App\Modules\Dian\Ubl;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * What the AttachedDocument and the PDF need from an ApplicationResponse: its number, its date and
 * time of issue (the moment of the validation) and the response code (02: accepted). Read from the
 * stored XML, which in this project always comes from the local DIAN simulator.
 */
final readonly class ApplicationResponseSummary
{
    private const string NS_CBC = UblDocumentXml::NS_CBC;

    private const string NS_CAC = UblDocumentXml::NS_CAC;

    public function __construct(
        public string $id,
        public string $issueDate,
        public string $issueTime,
        public string $responseCode,
        public ?string $documentKey,
    ) {}

    public static function fromXml(string $xml): self
    {
        $dom = new DOMDocument;

        if (! $dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('The ApplicationResponse is not well formed.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', self::NS_CBC);
        $xpath->registerNamespace('cac', self::NS_CAC);

        $value = function (string $path) use ($xpath): ?string {
            $nodes = $xpath->query($path);
            $node = $nodes === false ? null : $nodes->item(0);
            $text = $node instanceof DOMNode ? trim($node->textContent) : null;

            return $text === '' ? null : $text;
        };

        return new self(
            id: $value('/*/cbc:ID') ?? throw new RuntimeException('The ApplicationResponse has no ID.'),
            issueDate: $value('/*/cbc:IssueDate') ?? throw new RuntimeException('The ApplicationResponse has no IssueDate.'),
            issueTime: $value('/*/cbc:IssueTime') ?? throw new RuntimeException('The ApplicationResponse has no IssueTime.'),
            responseCode: $value('/*/cac:DocumentResponse/cac:Response/cbc:ResponseCode') ?? throw new RuntimeException('The ApplicationResponse has no ResponseCode.'),
            documentKey: $value('/*/cac:DocumentResponse/cac:DocumentReference/cbc:UUID'),
        );
    }

    /** The instant of the validation, from its Colombian date and time with offset. */
    public function issuedAt(): Carbon
    {
        return Carbon::parse("{$this->issueDate}T{$this->issueTime}");
    }
}
