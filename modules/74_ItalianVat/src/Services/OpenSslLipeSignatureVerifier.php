<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use Modules\ItalianVat\Contracts\LipeSignatureVerifier;

/**
 * Checks a detached RSA signature (SHA-256) of the LIPE file against the public key of the signer certificate, and that the
 * certificate is within its validity period.
 */
final class OpenSslLipeSignatureVerifier implements LipeSignatureVerifier
{
    public function verify(string $xml, string $signature, string $certificate): bool
    {
        $raw = base64_decode($signature, true);
        $key = openssl_pkey_get_public($certificate);

        if ($raw === false || $raw === '' || $key === false) {
            return false;
        }

        $parsed = openssl_x509_parse($certificate);

        if ($parsed === false || time() < (int) $parsed['validFrom_time_t'] || time() > (int) $parsed['validTo_time_t']) {
            return false;
        }

        return openssl_verify($xml, $raw, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public function fingerprint(string $certificate): ?string
    {
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

        return $fingerprint === false ? null : strtolower($fingerprint);
    }
}
