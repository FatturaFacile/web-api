<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\DTOs;

/**
 * What a recipient submitted to sign, together with the PDF the signature applies to and what is known about the request.
 */
final readonly class SigningInput
{
    public function __construct(
        public SignatureAppearance $appearance,
        public string $pdf,
        public ?string $otp,
        public string $signatureMethod,
        public ?string $signatureData,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
