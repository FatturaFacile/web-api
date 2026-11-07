<?php

declare(strict_types=1);

namespace Modules\Customs\Services\Edec;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Modules\Customs\DTOs\SignatureCheck;
use Modules\Customs\Enums\SignatureStatus;
use OpenSSLCertificate;

/**
 * Checks the WS-Security XML signature of an e-dec answer with DOM canonicalization and openssl only.
 *
 * e-dec signs the SOAP body with an exclusive-C14N XML signature whose certificate travels in a BinarySecurityToken.
 * The check covers the signature value, the digest of every reference, that the SOAP body itself is signed (no wrapped
 * copy), the certificate chain up to the BAZG trust anchors and the certificate validity at signing time.
 * Revocation is not checked: the report says so, because BAZG asks for it in the verification record.
 */
final class WsSecuritySignatureVerifier
{
    private const string SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

    private const string DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const string WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';

    private const string WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    private const string EC = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    /** @var array<string, int> */
    private const array SIGNATURE_METHODS = [
        'http://www.w3.org/2000/09/xmldsig#rsa-sha1' => OPENSSL_ALGO_SHA1,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
    ];

    /** @var array<string, string> */
    private const array DIGEST_METHODS = [
        'http://www.w3.org/2000/09/xmldsig#sha1' => 'sha1',
        'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
    ];

    /**
     * @param  list<string>|null  $trustAnchors  certificate files, intermediates and roots; the published ones by default
     */
    public function __construct(private readonly ?array $trustAnchors = null) {}

    public function verify(string $envelope): SignatureCheck
    {
        $document = $this->load($envelope);
        $report = ['checked_at' => now()->toIso8601String(), 'revocation_checked' => false];

        if ($document === null) {
            return new SignatureCheck(SignatureStatus::Invalid, $report + ['reason' => 'not_xml']);
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('soap', self::SOAP);
        $xpath->registerNamespace('ds', self::DS);
        $xpath->registerNamespace('wsse', self::WSSE);
        $xpath->registerNamespace('wsu', self::WSU);
        $xpath->registerNamespace('ec', self::EC);

        $signature = $xpath->query('/soap:Envelope/soap:Header/wsse:Security/ds:Signature')->item(0);

        if (! $signature instanceof DOMElement) {
            return new SignatureCheck(SignatureStatus::NotSigned, $report + ['reason' => 'no_signature']);
        }

        $signedInfo = $xpath->query('ds:SignedInfo', $signature)->item(0);
        $method = (string) $xpath->evaluate('string(ds:SignedInfo/ds:SignatureMethod/@Algorithm)', $signature);
        $canonicalization = (string) $xpath->evaluate('string(ds:SignedInfo/ds:CanonicalizationMethod/@Algorithm)', $signature);

        if (! $signedInfo instanceof DOMElement || ! isset(self::SIGNATURE_METHODS[$method]) || $canonicalization !== self::EC) {
            return new SignatureCheck(SignatureStatus::Unsupported, $report + ['reason' => 'unsupported_algorithm', 'algorithm' => $method]);
        }

        $certificate = $this->certificateOf($xpath, $signature);

        if ($certificate === null) {
            return new SignatureCheck(SignatureStatus::Unsupported, $report + ['reason' => 'no_certificate']);
        }

        $details = (array) openssl_x509_parse($certificate);
        $report['certificate'] = [
            'subject' => $details['subject']['CN'] ?? null,
            'issuer' => $details['issuer']['CN'] ?? null,
            'valid_to' => isset($details['validTo_time_t']) ? date(DATE_ATOM, (int) $details['validTo_time_t']) : null,
        ];

        $digests = $this->checkReferences($xpath, $signature);
        $report['references_valid'] = $digests['valid'];
        $report['body_signed'] = $digests['body_signed'];

        $signatureValue = base64_decode(preg_replace('/\s+/', '', (string) $xpath->evaluate('string(ds:SignatureValue)', $signature)) ?? '', true);
        $prefixes = $this->inclusivePrefixes($xpath, 'ds:CanonicalizationMethod', $signedInfo);
        $canonical = $signedInfo->C14N(true, false, null, $prefixes);
        $report['signature_valid'] = $signatureValue !== false && $canonical !== false
            && openssl_verify($canonical, $signatureValue, $certificate, self::SIGNATURE_METHODS[$method]) === 1;

        $report['chain_valid'] = $this->chainsToAnchor($certificate);
        $report['valid_at_signing'] = $this->wasValidAt($xpath, $details);

        $isValid = $report['signature_valid'] && $report['references_valid'] && $report['body_signed'] && $report['chain_valid'] && $report['valid_at_signing'];

        return new SignatureCheck($isValid ? SignatureStatus::Verified : SignatureStatus::Invalid, $report);
    }

    private function load(string $xml): ?DOMDocument
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

    private function certificateOf(DOMXPath $xpath, DOMElement $signature): ?OpenSSLCertificate
    {
        $uri = (string) $xpath->evaluate('string(ds:KeyInfo/wsse:SecurityTokenReference/wsse:Reference/@URI)', $signature);

        if (! str_starts_with($uri, '#')) {
            return null;
        }

        $tokens = $xpath->query('/soap:Envelope/soap:Header/wsse:Security/wsse:BinarySecurityToken[@wsu:Id="'.$this->xpathSafe(substr($uri, 1)).'"]');

        if ($tokens->length !== 1) {
            return null;
        }

        $body = (string) preg_replace('/\s+/', '', (string) $tokens->item(0)?->textContent);
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n")."-----END CERTIFICATE-----\n";
        $certificate = openssl_x509_read($pem);

        return $certificate === false ? null : $certificate;
    }

    /**
     * Every reference must resolve to exactly one element, and one of them must be the body of the envelope. The
     * decision is read from that same body, so a signed copy placed elsewhere in the message proves nothing.
     *
     * @return array{valid: bool, body_signed: bool}
     */
    private function checkReferences(DOMXPath $xpath, DOMElement $signature): array
    {
        $references = $xpath->query('ds:SignedInfo/ds:Reference', $signature);
        $body = $xpath->query('/soap:Envelope/soap:Body')->item(0);
        $isValid = $references->length > 0;
        $isBodySigned = false;

        foreach ($references as $reference) {
            $uri = (string) $reference->getAttribute('URI');
            $algorithm = (string) $xpath->evaluate('string(ds:DigestMethod/@Algorithm)', $reference);
            $targets = str_starts_with($uri, '#') ? $xpath->query('//*[@wsu:Id="'.$this->xpathSafe(substr($uri, 1)).'"]') : null;

            if ($targets === null || $targets === false || $targets->length !== 1 || ! isset(self::DIGEST_METHODS[$algorithm])) {
                $isValid = false;

                continue;
            }

            /** @var DOMElement $target */
            $target = $targets->item(0);
            $canonical = $target->C14N(true, false, null, $this->inclusivePrefixes($xpath, 'ds:Transforms/ds:Transform', $reference));
            $expected = preg_replace('/\s+/', '', (string) $xpath->evaluate('string(ds:DigestValue)', $reference));

            if ($canonical === false || ! hash_equals((string) $expected, base64_encode(hash(self::DIGEST_METHODS[$algorithm], $canonical, true)))) {
                $isValid = false;
            }

            if ($target->isSameNode($body)) {
                $isBodySigned = true;
            }
        }

        return ['valid' => $isValid, 'body_signed' => $isBodySigned];
    }

    /**
     * @return list<string>|null
     */
    private function inclusivePrefixes(DOMXPath $xpath, string $path, DOMElement $context): ?array
    {
        $list = trim((string) $xpath->evaluate("string({$path}/ec:InclusiveNamespaces/@PrefixList)", $context));

        return $list === '' ? null : array_map(static fn (string $prefix): string => $prefix === '#default' ? '' : $prefix, preg_split('/\s+/', $list) ?: []);
    }

    private function chainsToAnchor(OpenSSLCertificate $certificate): bool
    {
        $anchors = $this->anchors();
        $current = $certificate;

        for ($depth = 0; $depth < 5; $depth++) {
            $issuer = $this->issuerOf($current, $anchors);

            if ($issuer === null) {
                return false;
            }

            if (openssl_x509_parse($issuer)['subject'] === openssl_x509_parse($issuer)['issuer']) {
                return true;
            }

            $current = $issuer;
        }

        return false;
    }

    /**
     * @param  list<OpenSSLCertificate>  $anchors
     */
    private function issuerOf(OpenSSLCertificate $certificate, array $anchors): ?OpenSSLCertificate
    {
        foreach ($anchors as $anchor) {
            $publicKey = openssl_pkey_get_public($anchor);

            if ($publicKey !== false && openssl_x509_verify($certificate, $publicKey) === 1) {
                return $anchor;
            }
        }

        return null;
    }

    /**
     * @return list<OpenSSLCertificate>
     */
    private function anchors(): array
    {
        $files = $this->trustAnchors ?? [
            dirname(__DIR__, 3).'/resources/certs/swiss-government-regular-ca-02.cer',
            dirname(__DIR__, 3).'/resources/certs/swiss-government-root-ca-iii.cer',
        ];

        $certificates = [];

        foreach ($files as $file) {
            $raw = (string) file_get_contents($file);
            $pem = str_contains($raw, 'BEGIN CERTIFICATE') ? $raw : "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($raw), 64, "\n")."-----END CERTIFICATE-----\n";
            $certificate = openssl_x509_read($pem);

            if ($certificate !== false) {
                $certificates[] = $certificate;
            }
        }

        return $certificates;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function wasValidAt(DOMXPath $xpath, array $details): bool
    {
        $created = strtotime((string) $xpath->evaluate('string(/soap:Envelope/soap:Header/wsse:Security/wsu:Timestamp/wsu:Created)'));
        $moment = $created === false ? time() : $created;

        return $moment >= (int) ($details['validFrom_time_t'] ?? PHP_INT_MAX) && $moment <= (int) ($details['validTo_time_t'] ?? 0);
    }

    private function xpathSafe(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._:-]/', '', $value);
    }
}
