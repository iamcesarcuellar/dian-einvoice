<?php

namespace App\Modules\Dian\Transmission;

use App\Support\Xml\SafeXml;
use App\Support\Xml\XmlRefused;
use DOMDocument;
use DOMElement;
use DOMNameSpaceNode;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * SOAP 1.2 adapter of DianGateway (annex 1.9, sections 7.5, 7.6, 7.10 and 7.11): a document/literal
 * envelope with the WS-Addressing Action and To headers, the operation in the wcf namespace and
 * the zip in base64. The URL is configurable and points to the local simulator; the WS-Security
 * signature of the header that the real service requires is not built yet.
 */
final class SoapDianGateway implements DianGateway
{
    private const string NS_SOAP = 'http://www.w3.org/2003/05/soap-envelope';

    private const string NS_WCF = 'http://wcf.dian.colombia';

    private const string NS_WSA = 'http://www.w3.org/2005/08/addressing';

    private const string NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    private const string NS_XMLNS = 'http://www.w3.org/2000/xmlns/';

    private const string ACTION = 'http://wcf.dian.colombia/IWcfDianCustomerServices/';

    /** "Regla: FAD06, Rechazo: Valor del CUFE no está calculado correctamente." */
    private const string MESSAGE = '/^Regla:\s*([A-Za-z0-9]+)\s*,\s*(Rechazo|Notificaci[oó]n)\s*:\s*(.*)$/su';

    /** "Regla: AA09 Valor del CUFE no está calculado correctamente." (older form in the annex). */
    private const string SHORT_MESSAGE = '/^Regla:\s*([A-Za-z0-9]+)\s+(.*)$/su';

    public function __construct(
        private readonly string $url,
        private readonly float $connectTimeout,
        private readonly float $timeout,
    ) {}

    public function name(): string
    {
        // The only DIAN this project talks to.
        return 'simulator';
    }

    public function endpoint(): string
    {
        return $this->url;
    }

    public function sendBillSync(string $zipFileName, string $zip): GatewayCall
    {
        return $this->call(self::SEND_BILL_SYNC, ['fileName' => $zipFileName, 'contentFile' => base64_encode($zip)]);
    }

    public function getStatus(string $trackId): GatewayCall
    {
        return $this->call(self::GET_STATUS, ['trackId' => $trackId]);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function call(string $operation, array $parameters): GatewayCall
    {
        $envelope = $this->envelope($operation, $parameters);
        $started = hrtime(true);

        try {
            $response = Http::connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->withBody($envelope, sprintf('application/soap+xml; charset=utf-8; action="%s"', self::ACTION.$operation))
                ->post($this->url);
        } catch (Throwable $exception) {
            return $this->transportFailure($operation, $exception, $this->elapsed($started));
        }

        $duration = $this->elapsed($started);
        $status = $response->status();

        if ($status >= 500) {
            return new GatewayCall($operation, TransmissionOutcome::ServerError, $duration, $status, error: trim("HTTP {$status}. ".($this->faultReason($response->body()) ?? '')));
        }

        if ($status >= 400) {
            return new GatewayCall($operation, TransmissionOutcome::ClientError, $duration, $status, error: trim("HTTP {$status}. ".($this->faultReason($response->body()) ?? '')));
        }

        $answer = $this->answer($operation, $response->body());

        if ($answer === null) {
            return new GatewayCall($operation, TransmissionOutcome::InvalidResponse, $duration, $status, error: "HTTP {$status} without a {$operation} result.");
        }

        $outcome = match (true) {
            $operation === self::SEND_BILL_SYNC => TransmissionOutcome::Responded,
            $answer->notFound() => TransmissionOutcome::NotFound,
            default => TransmissionOutcome::Found,
        };

        return new GatewayCall($operation, $outcome, $duration, $status, $answer);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function envelope(string $operation, array $parameters): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $envelope = $dom->createElementNS(self::NS_SOAP, 'soap:Envelope');
        $envelope->setAttributeNS(self::NS_XMLNS, 'xmlns:wcf', self::NS_WCF);
        $dom->appendChild($envelope);

        $header = $dom->createElementNS(self::NS_SOAP, 'soap:Header');
        $header->setAttributeNS(self::NS_XMLNS, 'xmlns:wsa', self::NS_WSA);
        $envelope->appendChild($header);
        $this->text($header, self::NS_WSA, 'wsa:Action', self::ACTION.$operation);
        $this->text($header, self::NS_WSA, 'wsa:To', $this->url);

        $body = $dom->createElementNS(self::NS_SOAP, 'soap:Body');
        $envelope->appendChild($body);
        $request = $dom->createElementNS(self::NS_WCF, 'wcf:'.$operation);
        $body->appendChild($request);

        foreach ($parameters as $name => $value) {
            $this->text($request, self::NS_WCF, 'wcf:'.$name, $value);
        }

        return (string) $dom->saveXML();
    }

    private function text(DOMElement $parent, string $namespace, string $name, string $value): void
    {
        $dom = $parent->ownerDocument ?? throw new LogicException('An element of the envelope belongs to its document.');
        $element = $dom->createElementNS($namespace, $name);
        $element->appendChild($dom->createTextNode($value));
        $parent->appendChild($element);
    }

    /** The {Operation}Result of the answer, or null when the body is not one. */
    private function answer(string $operation, string $body): ?DianAnswer
    {
        $xpath = $this->xpath($body);
        $result = $xpath === null ? null : $this->first($xpath, "//*[local-name()='{$operation}Result']");

        if ($xpath === null || ! $result instanceof DOMElement) {
            return null;
        }

        $value = function (string ...$names) use ($xpath, $result): ?string {
            foreach ($names as $name) {
                $node = $this->first($xpath, "./*[local-name()='{$name}']", $result);

                if ($node instanceof DOMElement && $node->getAttributeNS(self::NS_XSI, 'nil') !== 'true') {
                    return trim($node->textContent);
                }
            }

            return null;
        };

        $statusCode = $value('StatusCode');

        if ($statusCode === null || $statusCode === '') {
            return null;
        }

        $isValid = strtolower((string) $value('IsValid')) === 'true';
        $messages = [];

        $nodes = $xpath->query("./*[local-name()='ErrorMessage']/*[local-name()='string']", $result);

        foreach ($nodes === false ? [] : $nodes as $node) {
            $text = $node instanceof DOMNode ? trim($node->textContent) : '';

            if ($text !== '') {
                $messages[] = $this->message($text, $isValid);
            }
        }

        $base64 = $value('XmlBase64Bytes');
        $applicationResponse = filled($base64) ? base64_decode((string) $base64, true) : false;

        return new DianAnswer(
            isValid: $isValid,
            statusCode: $statusCode,
            statusDescription: $this->limit($value('StatusDescription'), 500),
            statusMessage: $this->limit($value('StatusMessage'), 1000),
            messages: $messages,
            applicationResponse: $applicationResponse === false ? null : $applicationResponse,
            // The annex writes these two with a lowercase x in SendBillSync and uppercase in GetStatus.
            documentKey: $value('XmlDocumentKey', 'xmlDocumentKey'),
            fileName: $value('XmlFileName', 'xmlFileName'),
        );
    }

    /**
     * @return array{rule: string|null, kind: string, message: string}
     */
    private function message(string $text, bool $isValid): array
    {
        if (preg_match(self::MESSAGE, $text, $match) === 1) {
            return ['rule' => $match[1], 'kind' => str_starts_with($match[2], 'Rechazo') ? 'rejection' : 'notification', 'message' => trim($match[3])];
        }

        $kind = $isValid ? 'notification' : 'rejection';

        if (preg_match(self::SHORT_MESSAGE, $text, $match) === 1) {
            return ['rule' => $match[1], 'kind' => $kind, 'message' => trim($match[2])];
        }

        return ['rule' => null, 'kind' => $kind, 'message' => $text];
    }

    /** The reason of a SOAP fault, if the body is one. */
    private function faultReason(string $body): ?string
    {
        $xpath = $this->xpath($body);
        $node = $xpath === null ? null : $this->first($xpath, "//*[local-name()='Fault']//*[local-name()='Text' or local-name()='faultstring']");

        return $node instanceof DOMNode ? $this->limit(trim($node->textContent), 300) : null;
    }

    /** The first node an XPath expression finds, if any. */
    private function first(DOMXPath $xpath, string $expression, ?DOMNode $context = null): DOMNode|DOMNameSpaceNode|null
    {
        $nodes = $xpath->query($expression, $context);

        return $nodes === false ? null : $nodes->item(0);
    }

    private function xpath(string $body): ?DOMXPath
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            return new DOMXPath(SafeXml::load($body));
        } catch (XmlRefused) {
            // Not well formed, or with a DTD: not an answer of the service.
            return null;
        }
    }

    /**
     * No HTTP answer. cURL tells whether the request could have gone out: a connection that was
     * never opened sent nothing; a timeout or a dropped connection after that may have delivered it.
     */
    private function transportFailure(string $operation, Throwable $exception, int $duration): GatewayCall
    {
        $message = $exception->getMessage();
        $errno = preg_match('/cURL error (\d+)/', $message, $match) === 1 ? (int) $match[1] : null;
        $sent = $operation === self::SEND_BILL_SYNC;
        $detail = Str::limit($message, 400);

        $notConnected = $errno === 6 || $errno === 7
            || ($errno === 28 && preg_match('/Failed to connect|Connection timed out|Resolving timed out|Could not resolve/i', $message) === 1);

        if ($notConnected) {
            return new GatewayCall($operation, TransmissionOutcome::Refused, $duration, error: "Could not connect. {$detail}");
        }

        if ($errno === 28) {
            return new GatewayCall($operation, TransmissionOutcome::Timeout, $duration, error: sprintf('No answer within %s s. %s', rtrim(rtrim(number_format($this->timeout, 1, '.', ''), '0'), '.'), $detail), mayHaveArrived: $sent);
        }

        return new GatewayCall($operation, TransmissionOutcome::NoResponse, $duration, error: "The connection closed without an answer. {$detail}", mayHaveArrived: $sent);
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }

    private function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : Str::limit($value, $length - 3);
    }
}
