<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use Carbon\CarbonImmutable;

/**
 * Inspects the digital signature of an uploaded tender document: CAdES envelopes (.p7m, DER or base64) and PAdES signed PDFs.
 *
 * The inspection is structural: it confirms that the file really carries a PKCS#7 signedData or a PDF signature dictionary
 * with a byte range and reads the signer certificate when the OpenSSL extension can. Validating the certificate chain against
 * the trusted lists (EUTL, AgID) is left to the qualified trust service of the company.
 */
final class SignatureInspector
{
    /** OID 1.2.840.113549.1.7.2 (signedData) as DER bytes. */
    private const string SignedDataOid = "\x2A\x86\x48\x86\xF7\x0D\x01\x07\x02";

    /**
     * @return array{format: string, status: string, signer: string|null, signed_at: string|null, verified_at: string, message_code: string}
     */
    public function inspect(string $contents, string $fileName, bool $signatureRequired): array
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $verifiedAt = CarbonImmutable::now()->toIso8601String();

        if ($extension === 'p7m' || $extension === 'p7s') {
            return $this->cades($contents, $verifiedAt);
        }

        if (str_starts_with(ltrim($contents), '%PDF')) {
            return $this->pades($contents, $signatureRequired, $verifiedAt);
        }

        return [
            'format' => 'none',
            'status' => $signatureRequired ? 'unsigned' : 'not_required',
            'signer' => null,
            'signed_at' => null,
            'verified_at' => $verifiedAt,
            'message_code' => $signatureRequired ? 'signature_required' : 'signature_not_required',
        ];
    }

    /**
     * Whether the extension is acceptable for a document that must be signed.
     */
    public function isSignatureFormat(string $fileName): bool
    {
        return in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['p7m', 'p7s', 'pdf'], true);
    }

    /**
     * @return array{format: string, status: string, signer: string|null, signed_at: string|null, verified_at: string, message_code: string}
     */
    private function cades(string $contents, string $verifiedAt): array
    {
        $der = $this->toDer($contents);
        $valid = $der !== null && strlen($der) > 32 && ord($der[0]) === 0x30 && str_contains(substr($der, 0, 64), self::SignedDataOid);
        $signer = $valid ? $this->signerOf((string) $der) : null;

        return [
            'format' => 'p7m',
            'status' => $valid ? 'valid' : 'invalid',
            'signer' => $signer,
            'signed_at' => null,
            'verified_at' => $verifiedAt,
            'message_code' => $valid ? 'cades_structure_verified' : 'cades_malformed',
        ];
    }

    /**
     * @return array{format: string, status: string, signer: string|null, signed_at: string|null, verified_at: string, message_code: string}
     */
    private function pades(string $contents, bool $signatureRequired, string $verifiedAt): array
    {
        $hasByteRange = preg_match('/\/ByteRange\s*\[\s*\d+\s+\d+\s+\d+\s+\d+\s*\]/', $contents) === 1;
        $hasSignature = $hasByteRange && preg_match('/\/(?:Type\s*\/Sig|SubFilter\s*\/(?:ETSI\.CAdES\.detached|adbe\.pkcs7\.(?:detached|sha1)))/', $contents) === 1;
        $signedAt = null;

        if ($hasSignature && preg_match('/\/M\s*\(D:(\d{14})/', $contents, $match) === 1) {
            $signedAt = CarbonImmutable::createFromFormat('YmdHis', $match[1])->toIso8601String();
        }

        $signer = null;
        if ($hasSignature && preg_match('/\/Name\s*\(([^)]{1,120})\)/', $contents, $name) === 1) {
            $signer = $name[1];
        }

        if (! $hasSignature) {
            return ['format' => 'pades', 'status' => $signatureRequired ? 'unsigned' : 'not_required', 'signer' => null, 'signed_at' => null, 'verified_at' => $verifiedAt, 'message_code' => $signatureRequired ? 'pades_missing' : 'signature_not_required'];
        }

        return ['format' => 'pades', 'status' => 'valid', 'signer' => $signer, 'signed_at' => $signedAt, 'verified_at' => $verifiedAt, 'message_code' => 'pades_structure_verified'];
    }

    private function toDer(string $contents): ?string
    {
        if ($contents !== '' && ord($contents[0]) === 0x30) {
            return $contents;
        }

        $base64 = preg_replace('/-----[A-Z0-9 ]+-----|\s+/', '', $contents);
        $decoded = is_string($base64) ? base64_decode($base64, true) : false;

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    private function signerOf(string $der): ?string
    {
        if (! function_exists('openssl_pkcs7_read')) {
            return null;
        }

        $pem = "-----BEGIN PKCS7-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PKCS7-----\n";
        $certificates = [];

        if (@openssl_pkcs7_read($pem, $certificates) !== true || $certificates === []) {
            return null;
        }

        $parsed = @openssl_x509_parse((string) $certificates[0]);

        return is_array($parsed) ? (string) ($parsed['subject']['CN'] ?? '') ?: null : null;
    }
}
