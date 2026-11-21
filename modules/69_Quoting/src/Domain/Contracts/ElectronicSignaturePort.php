<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Contracts;

use Modules\Quoting\Domain\Exceptions\QuoteAccessDeniedException;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Models\CpqSignatureEnvelope;

/**
 * A signature provider: the native OTP flow (SES) or a trust service provider (AES/QES, eIDAS 910/2014 and ZertES SR 943.03).
 */
interface ElectronicSignaturePort
{
    public function provider(): string;

    /**
     * Starts the ceremony. Returns what the portal shows next: ['otp_sent' => true] or ['redirect_url' => '...'].
     *
     * @return array<string, mixed>
     */
    public function initiate(CpqQuote $quote, CpqSignatureEnvelope $envelope): array;

    /**
     * Checks the proof of the signer (the OTP code, or the signed callback of the provider).
     *
     * @param  array<string, mixed>  $proof
     * @return array<string, mixed> Verification metadata to store on the envelope
     *
     * @throws QuoteAccessDeniedException when the proof is not valid
     */
    public function verify(CpqSignatureEnvelope $envelope, array $proof): array;
}
