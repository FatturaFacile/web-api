<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Support;

use DateTimeImmutable;
use DateTimeZone;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use phpseclib3\File\ASN1;
use phpseclib3\Math\BigInteger;

/**
 * Builds the TimeStampReq and checks the TimeStampResp of RFC 3161 with the ASN.1 encoder of phpseclib.
 *
 * Checked on the response: PKIStatus (granted or grantedWithMods), a token that is a CMS SignedData of content type
 * id-ct-TSTInfo, the message imprint (SHA-256 and value), the nonce, the generation time (a valid GeneralizedTime
 * within a tolerance of the local clock) and the signature of the token (messageDigest attribute against the TSTInfo,
 * and the signature over the signed attributes with the public key of the certificate the token carries).
 *
 * NOT checked: that the certificate chains to a trusted (qualified) root, its validity, its revocation and the
 * extended key usage. A genuine verdict on who stamped the hash needs that validation, which the token (stored
 * unchanged, base64 DER) allows to be done by a qualified validation service. Whether a TSA is a qualified provider is
 * never inferred here; it is a declaration of the tenant.
 *
 * Source: RFC 3161 (Internet X.509 PKI Time-Stamp Protocol) and RFC 5652 (CMS), as known to the author; the module has
 * been exercised only against locally generated responses, not against a live TSA.
 */
final class Rfc3161Codec
{
    public const string OidSha256 = '2.16.840.1.101.3.4.2.1';

    private const string OidSignedData = '1.2.840.113549.1.7.2';

    private const string OidTstInfo = '1.2.840.113549.1.9.16.1.4';

    private const string OidMessageDigest = '1.2.840.113549.1.9.4';

    private const string OidContentType = '1.2.840.113549.1.9.3';

    /**
     * Signature and digest algorithms understood for the token signature: OID => openssl algorithm.
     *
     * @var array<string, int|string>
     */
    private const array Digests = [
        '1.3.14.3.2.26' => OPENSSL_ALGO_SHA1,
        '2.16.840.1.101.3.4.2.1' => OPENSSL_ALGO_SHA256,
        '2.16.840.1.101.3.4.2.2' => OPENSSL_ALGO_SHA384,
        '2.16.840.1.101.3.4.2.3' => OPENSSL_ALGO_SHA512,
        '1.2.840.113549.1.1.5' => OPENSSL_ALGO_SHA1,
        '1.2.840.113549.1.1.11' => OPENSSL_ALGO_SHA256,
        '1.2.840.113549.1.1.12' => OPENSSL_ALGO_SHA384,
        '1.2.840.113549.1.1.13' => OPENSSL_ALGO_SHA512,
        '1.2.840.10045.4.3.2' => OPENSSL_ALGO_SHA256,
        '1.2.840.10045.4.3.3' => OPENSSL_ALGO_SHA384,
        '1.2.840.10045.4.3.4' => OPENSSL_ALGO_SHA512,
    ];

    private const string OidRsaEncryption = '1.2.840.113549.1.1.1';

    /**
     * The DER TimeStampReq for a SHA-256 digest: version 1, message imprint, nonce, certReq.
     *
     * @param  string  $sha256Binary  the 32 raw bytes of the digest
     */
    public static function buildRequest(string $sha256Binary, BigInteger $nonce, bool $certReq = true): string
    {
        $mapping = [
            'type' => ASN1::TYPE_SEQUENCE,
            'children' => [
                'version' => ['type' => ASN1::TYPE_INTEGER],
                'messageImprint' => [
                    'type' => ASN1::TYPE_SEQUENCE,
                    'children' => [
                        'hashAlgorithm' => [
                            'type' => ASN1::TYPE_SEQUENCE,
                            'children' => ['algorithm' => ['type' => ASN1::TYPE_OBJECT_IDENTIFIER], 'parameters' => ['type' => ASN1::TYPE_NULL]],
                        ],
                        'hashedMessage' => ['type' => ASN1::TYPE_OCTET_STRING],
                    ],
                ],
                'nonce' => ['type' => ASN1::TYPE_INTEGER],
                'certReq' => ['type' => ASN1::TYPE_BOOLEAN],
            ],
        ];

        $der = ASN1::encodeDER([
            'version' => 1,
            'messageImprint' => ['hashAlgorithm' => ['algorithm' => self::OidSha256, 'parameters' => null], 'hashedMessage' => $sha256Binary],
            'nonce' => $nonce,
            'certReq' => $certReq,
        ], $mapping);

        if ($der === '') {
            throw new DocumentTrustException('The time-stamp request could not be encoded.');
        }

        return $der;
    }

    /**
     * Validate a TimeStampResp and return the token.
     *
     * @param  string  $sha256Binary  the digest that was sent
     * @param  int  $toleranceSeconds  accepted distance between the generation time and the local clock
     *
     * @throws DocumentTrustException when the response is refused, malformed or fails a check
     */
    public static function parseResponse(string $response, string $sha256Binary, BigInteger $nonce, string $provider, bool $qualified, int $toleranceSeconds = 600): TimestampToken
    {
        $root = self::decode($response);
        $parts = self::children($root, 2, 'The time-stamp response is malformed.');
        $status = self::children($parts[0], 1, 'The time-stamp response has no status.');
        $code = $status[0]['content'] instanceof BigInteger ? (int) $status[0]['content']->toString() : -1;

        if ($code !== 0 && $code !== 1) {
            throw new DocumentTrustException("The time-stamp authority refused the request (status {$code}).");
        }

        if (! isset($parts[1])) {
            throw new DocumentTrustException('The time-stamp response carries no token.');
        }

        $token = $parts[1];
        $contentInfo = self::children($token, 2, 'The time-stamp token is malformed.');

        if (self::oid($contentInfo[0]) !== self::OidSignedData) {
            throw new DocumentTrustException('The time-stamp token is not a CMS SignedData.');
        }

        $signedData = self::children(self::children($contentInfo[1], 1, 'The time-stamp token is malformed.')[0], 3, 'The signed data is malformed.');
        $encapsulated = self::children($signedData[2], 2, 'The signed content is malformed.');

        if (self::oid($encapsulated[0]) !== self::OidTstInfo) {
            throw new DocumentTrustException('The signed content is not a TSTInfo.');
        }

        $tstOctets = self::octets($response, self::children($encapsulated[1], 1, 'The signed content is malformed.')[0]);

        $tst = self::children(self::decode($tstOctets), 5, 'The TSTInfo is malformed.');
        self::checkImprint($tst[2], $sha256Binary);
        $genTime = self::generationTime($tst, $tstOctets, $toleranceSeconds);
        self::checkNonce($tst, $nonce);
        self::checkSignature($response, $signedData, $tstOctets);

        return new TimestampToken($provider, base64_encode(self::raw($response, $token)), $genTime, $qualified);
    }

    /**
     * @param  array<string, mixed>  $imprint
     */
    private static function checkImprint(array $imprint, string $sha256Binary): void
    {
        $parts = self::children($imprint, 2, 'The message imprint is malformed.');
        $algorithm = self::children($parts[0], 1, 'The message imprint is malformed.');

        if (self::oid($algorithm[0]) !== self::OidSha256 || ! hash_equals($sha256Binary, (string) $parts[1]['content'])) {
            throw new DocumentTrustException('The time-stamp does not match the hash that was sent.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tst
     */
    private static function generationTime(array $tst, string $tstOctets, int $toleranceSeconds): DateTimeImmutable
    {
        $element = $tst[4];

        if (($element['type'] ?? null) !== ASN1::TYPE_GENERALIZED_TIME) {
            throw new DocumentTrustException('The generation time of the time-stamp is missing.');
        }

        $text = substr($tstOctets, $element['start'] + $element['headerlength'], $element['length'] - $element['headerlength']);

        if (preg_match('/^(\d{14})(\.\d+)?Z$/', $text, $match) !== 1) {
            throw new DocumentTrustException('The generation time of the time-stamp is not a valid GeneralizedTime.');
        }

        $time = DateTimeImmutable::createFromFormat('YmdHis', $match[1], new DateTimeZone('UTC'));

        if ($time === false || abs($time->getTimestamp() - time()) > $toleranceSeconds) {
            throw new DocumentTrustException('The generation time of the time-stamp is not credible.');
        }

        return $time;
    }

    /**
     * @param  list<array<string, mixed>>  $tst
     */
    private static function checkNonce(array $tst, BigInteger $nonce): void
    {
        foreach (array_slice($tst, 5) as $element) {
            if (($element['type'] ?? null) === ASN1::TYPE_INTEGER) {
                if ($element['content'] instanceof BigInteger && $element['content']->equals($nonce)) {
                    return;
                }

                break;
            }
        }

        throw new DocumentTrustException('The nonce of the time-stamp does not match the request.');
    }

    /**
     * @param  list<array<string, mixed>>  $signedData
     */
    private static function checkSignature(string $response, array $signedData, string $tstOctets): void
    {
        $signerSet = end($signedData);
        $signer = self::children(self::children($signerSet, 1, 'The token has no signer.')[0], 5, 'The signer information is malformed.');
        $certificates = [];

        foreach (array_slice($signedData, 3) as $element) {
            if (($element['type'] ?? null) === ASN1::CLASS_CONTEXT_SPECIFIC && ($element['constant'] ?? null) === 0 && is_array($element['content'])) {
                $certificates = $element['content'];
            }
        }

        if ($certificates === []) {
            throw new DocumentTrustException('The time-stamp token carries no certificate, so its signature cannot be checked.');
        }

        $digestOid = self::oid(self::children($signer[2], 1, 'The signer information is malformed.')[0]);
        $attributesElement = $signer[3];
        $signatureOid = self::oid(self::children($signer[4], 1, 'The signer information is malformed.')[0]);
        $signature = (string) $signer[5]['content'];
        $attributes = self::children($attributesElement, 1, 'The signed attributes are missing.');
        $messageDigest = null;
        $contentType = null;

        foreach ($attributes as $attribute) {
            $pair = self::children($attribute, 2, 'The signed attributes are malformed.');
            $value = self::children($pair[1], 1, 'The signed attributes are malformed.')[0]['content'] ?? null;

            if (self::oid($pair[0]) === self::OidMessageDigest) {
                $messageDigest = (string) $value;
            } elseif (self::oid($pair[0]) === self::OidContentType) {
                $contentType = is_string($value) ? ASN1::getOID($value) : null;
            }
        }

        $digestAlgorithm = self::Digests[$digestOid] ?? null;
        $signatureAlgorithm = $signatureOid === self::OidRsaEncryption ? $digestAlgorithm : (self::Digests[$signatureOid] ?? null);

        if ($digestAlgorithm === null || $signatureAlgorithm === null) {
            throw new DocumentTrustException('The signature algorithm of the time-stamp token is not supported.');
        }

        $expected = hash(self::hashName($digestAlgorithm), $tstOctets, true);

        if ($messageDigest === null || ! hash_equals($expected, $messageDigest) || $contentType !== self::OidTstInfo) {
            throw new DocumentTrustException('The signed attributes of the time-stamp token do not match its content.');
        }

        $signedAttributes = "\x31".substr(self::raw($response, $attributesElement), 1);
        $key = self::signerKey($response, $signer[1], $certificates);

        if (openssl_verify($signedAttributes, $signature, $key, $signatureAlgorithm) !== 1) {
            throw new DocumentTrustException('The signature of the time-stamp token is invalid.');
        }
    }

    /**
     * Public key of the certificate named by the issuer and serial number of the signer.
     *
     * @param  array<string, mixed>  $sid
     * @param  list<array<string, mixed>>  $certificates
     */
    private static function signerKey(string $response, array $sid, array $certificates): \OpenSSLAsymmetricKey
    {
        $serial = self::children($sid, 2, 'The signer identifier is malformed.')[1]['content'] ?? null;

        foreach ($certificates as $certificate) {
            $tbs = self::children(self::children($certificate, 1, 'The certificate is malformed.')[0], 2, 'The certificate is malformed.');
            $index = ($tbs[0]['type'] ?? null) === ASN1::CLASS_CONTEXT_SPECIFIC ? 1 : 0;

            if ($serial instanceof BigInteger && ($tbs[$index]['content'] ?? null) instanceof BigInteger && $tbs[$index]['content']->equals($serial)) {
                $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode(self::raw($response, $certificate)), 64, "\n")."-----END CERTIFICATE-----\n";
                $key = openssl_pkey_get_public($pem);

                if ($key !== false) {
                    return $key;
                }
            }
        }

        throw new DocumentTrustException('The certificate of the time-stamp signer is not in the token.');
    }

    private static function hashName(int|string $algorithm): string
    {
        return match ($algorithm) {
            OPENSSL_ALGO_SHA1 => 'sha1',
            OPENSSL_ALGO_SHA384 => 'sha384',
            OPENSSL_ALGO_SHA512 => 'sha512',
            default => 'sha256',
        };
    }

    /**
     * Dotted OID of an element, whether phpseclib returned the number or a name it knows.
     *
     * @param  array<string, mixed>  $element
     */
    private static function oid(array $element): string
    {
        return ASN1::getOID((string) ($element['content'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $der): array
    {
        $decoded = @ASN1::decodeBER($der);

        if (! is_array($decoded) || ! isset($decoded[0]) || ! is_array($decoded[0])) {
            throw new DocumentTrustException('The time-stamp response is not valid ASN.1.');
        }

        return $decoded[0];
    }

    /**
     * The children of a constructed element, at least $minimum of them.
     *
     * @param  array<string, mixed>  $element
     * @return list<array<string, mixed>>
     */
    private static function children(array $element, int $minimum, string $message): array
    {
        $content = $element['content'] ?? null;

        if (! is_array($content) || count($content) < $minimum) {
            throw new DocumentTrustException($message);
        }

        return array_values($content);
    }

    /**
     * The raw DER bytes of an element, header included.
     *
     * @param  array<string, mixed>  $element
     */
    private static function raw(string $der, array $element): string
    {
        return substr($der, $element['start'], $element['length']);
    }

    /**
     * Content of an OCTET STRING element of the original response.
     *
     * @param  array<string, mixed>  $element
     */
    private static function octets(string $der, array $element): string
    {
        if (($element['type'] ?? null) !== ASN1::TYPE_OCTET_STRING || ! is_string($element['content'])) {
            throw new DocumentTrustException('The signed content is malformed.');
        }

        return $element['content'];
    }
}
