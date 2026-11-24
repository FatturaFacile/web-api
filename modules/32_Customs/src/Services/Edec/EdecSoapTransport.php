<?php

declare(strict_types=1);

namespace Modules\Customs\Services\Edec;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Customs\Exceptions\CustomsGatewayException;

/**
 * SOAP 1.1 over HTTPS with a client certificate, as in the BAZG service contracts of EdecService and EdecReceiptService.
 * PHP's soap extension is not a dependency of the project, and the XML has to reach the archive byte for byte.
 */
final class EdecSoapTransport
{
    private const string SOAP_NAMESPACE = 'http://schemas.xmlsoap.org/soap/envelope/';

    /**
     * Sends a message and returns the answer: the SOAP part, and the PDFs of a multipart answer.
     *
     * @return array{envelope: string, pdf: string|null}
     *
     * @throws CustomsGatewayException when nothing usable comes back
     */
    public function call(string $endpoint, string $soapAction, string $message): array
    {
        try {
            $response = Http::withOptions(array_filter([
                'cert' => $this->withPassphrase((string) config('customs.edec.client_certificate')),
                'ssl_key' => $this->withPassphrase((string) config('customs.edec.client_key')),
            ]))
                ->timeout((int) config('customs.edec.timeout_seconds'))
                ->withHeaders(['SOAPAction' => '"'.$soapAction.'"'])
                ->withBody($this->envelope($message), 'text/xml;charset=UTF-8')
                ->post($endpoint);
        } catch (ConnectionException $e) {
            throw new CustomsGatewayException('The customs system cannot be reached: '.$e->getMessage());
        }

        $parts = $this->split((string) $response->body(), (string) $response->header('Content-Type'));
        $fault = $parts['envelope'] === '' ? null : $this->faultOf($parts['envelope']);

        if ($fault !== null) {
            throw new CustomsGatewayException('The customs system refused the message: '.$fault);
        }

        if (! $response->successful() || $parts['envelope'] === '') {
            throw new CustomsGatewayException('The customs system answered with status '.$response->status().'.');
        }

        return $parts;
    }

    /**
     * The first element of the SOAP body, as it was received.
     *
     * @throws CustomsGatewayException when the message is not a SOAP envelope
     */
    public function bodyOf(string $envelope): string
    {
        $document = $this->parse($envelope);
        $bodies = $document === null ? null : $this->xpath($document)->query('/soap:Envelope/soap:Body/*');
        $body = $bodies !== null && $bodies !== false ? $bodies->item(0) : null;

        if (! $body instanceof DOMElement || $bodies->length !== 1) {
            throw new CustomsGatewayException('The answer of the customs system is not a valid SOAP message.');
        }

        $own = new DOMDocument('1.0', 'UTF-8');
        $own->appendChild($own->importNode($body, true));

        return (string) $own->saveXML();
    }

    private function envelope(string $message): string
    {
        $inner = (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $message);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="'.self::SOAP_NAMESPACE.'"><SOAP-ENV:Header/><SOAP-ENV:Body>'
            .$inner
            .'</SOAP-ENV:Body></SOAP-ENV:Envelope>';
    }

    /**
     * @return string|array{0: string, 1: string}
     */
    private function withPassphrase(string $path): string|array
    {
        $passphrase = (string) config('customs.edec.client_key_passphrase');

        return $passphrase === '' ? $path : [$path, $passphrase];
    }

    /**
     * @return array{envelope: string, pdf: string|null}
     */
    private function split(string $body, string $contentType): array
    {
        if (preg_match('/boundary="?([^";]+)"?/i', $contentType, $match) !== 1) {
            return ['envelope' => trim($body), 'pdf' => null];
        }

        $envelope = '';
        $pdf = null;

        foreach (explode('--'.$match[1], $body) as $part) {
            $separator = strpos($part, "\r\n\r\n");
            $length = 4;

            if ($separator === false) {
                $separator = strpos($part, "\n\n");
                $length = 2;
            }

            if ($separator === false) {
                continue;
            }

            $content = substr($part, $separator + $length);

            if ($envelope === '' && str_contains($content, 'Envelope')) {
                $envelope = trim($content);
            } elseif ($pdf === null && str_starts_with($content, '%PDF')) {
                $pdf = rtrim($content, "\r\n");
            }
        }

        return ['envelope' => $envelope, 'pdf' => $pdf];
    }

    private function faultOf(string $envelope): ?string
    {
        $document = $this->parse($envelope);

        if ($document === null) {
            return null;
        }

        $faults = $this->xpath($document)->query('/soap:Envelope/soap:Body/soap:Fault/faultstring');

        return $faults !== false && $faults->length > 0 ? trim((string) $faults->item(0)?->textContent) : null;
    }

    private function parse(string $xml): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;

            return $document->loadXML($xml, LIBXML_NONET) ? $document : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('soap', self::SOAP_NAMESPACE);

        return $xpath;
    }
}
