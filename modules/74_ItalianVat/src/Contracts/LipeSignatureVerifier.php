<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Contracts;

/**
 * Verifies the digital signature of a LIPE file. The Agenzia delle Entrate channels (Entratel, Desktop Telematico) require the
 * file to be signed by the declarant or the intermediary; the signing happens outside the ERP, with the qualified certificate
 * of the signer, and the ERP only checks that the signature belongs to the file it is about to transmit.
 */
interface LipeSignatureVerifier
{
    /**
     * @param  string  $xml  the exact content of the file that was signed
     * @param  string  $signature  the detached signature, base64 encoded
     * @param  string  $certificate  the certificate of the signer, PEM encoded
     */
    public function verify(string $xml, string $signature, string $certificate): bool;

    /**
     * The SHA-256 fingerprint of the certificate, as lowercase hexadecimal, or null when it cannot be read.
     */
    public function fingerprint(string $certificate): ?string;
}
