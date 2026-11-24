<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Drivers;

use Modules\DocumentTrust\Contracts\SignatureDriver;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Models\DocumentSignature;
use Modules\DocumentTrust\Models\DocumentSigningSetting;

/**
 * CAdES signature (.p7m, content enveloped) with the certificate of the tenant. PHP has no constant for the CMS_CADES
 * flag of OpenSSL 3, so the result is checked: a CMS without the signing-certificate-v2 attribute is not CAdES and is
 * refused. Legal value depends on the certificate, which must be a qualified one.
 */
final class CadesCertificateDriver implements SignatureDriver
{
    private const int OpensslCmsCades = 0x100000;

    /** DER of the OID 1.2.840.113549.1.9.16.2.47, signing-certificate-v2 */
    private const string SigningCertificateV2Oid = '2a864886f70d010910022f';

    /**
     * Driver name.
     */
    public function name(): string
    {
        return 'cades';
    }

    /**
     * Sign the content with the certificate of the tenant.
     */
    public function sign(string $content, ?DocumentSigningSetting $settings): array
    {
        if (empty($settings?->certificate) || empty($settings->private_key)) {
            throw new DocumentTrustException('The signing certificate of the company is not configured.');
        }

        $files = array_map(static fn (): string => (string) tempnam(sys_get_temp_dir(), 'cades-'), ['input', 'output', 'certificate', 'key']);
        [$input, $output, $certificate, $key] = $files;

        try {
            file_put_contents($input, $content);
            file_put_contents($certificate, $settings->certificate);
            file_put_contents($key, $settings->private_key);

            $signed = openssl_cms_sign(
                $input,
                $output,
                'file://'.$certificate,
                ['file://'.$key, (string) $settings->passphrase],
                [],
                OPENSSL_CMS_BINARY | self::OpensslCmsCades,
                OPENSSL_ENCODING_DER,
            );

            $der = (string) file_get_contents($output);
        } finally {
            array_map(unlink(...), $files);
        }

        if (! $signed || ! str_contains($der, (string) hex2bin(self::SigningCertificateV2Oid))) {
            throw new DocumentTrustException('This PHP build cannot produce a CAdES signature.');
        }

        return ['format' => 'cades-bes-p7m', 'signature' => base64_encode($der)];
    }

    /**
     * The CMS must be intact and the content it envelops must be the content the document has now. The signer
     * certificate is not validated against a trust list (`OPENSSL_CMS_NOVERIFY`).
     *
     * @return array{valid: bool, reason: ?string}
     */
    public function verify(string $content, DocumentSignature $signature): array
    {
        $der = base64_decode((string) $signature->signature, true);

        if ($der === false) {
            return ['valid' => false, 'reason' => 'signature_unreadable'];
        }

        $files = array_map(static fn (): string => (string) tempnam(sys_get_temp_dir(), 'cades-'), ['signed', 'content']);
        [$signed, $enveloped] = $files;

        try {
            file_put_contents($signed, $der);
            $intact = openssl_cms_verify($signed, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY, null, [], null, $enveloped, null, null, OPENSSL_ENCODING_DER);
            $original = (string) file_get_contents($enveloped);
        } finally {
            array_map(unlink(...), $files);
        }

        if ($intact !== true) {
            return ['valid' => false, 'reason' => 'signature_invalid'];
        }

        $matches = hash_equals($original, $content);

        return ['valid' => $matches, 'reason' => $matches ? null : 'content_changed'];
    }
}
