<?php

declare(strict_types=1);

namespace App\Services\Banking\Iso20022;

/**
 * One credit transfer of a pain.001 file. `reference` is the structured creditor reference: a 27 digit Swiss QR
 * reference (QRR, with a QR-IBAN) or an ISO 11649 reference (SCOR); without it `remittanceText` is sent unstructured.
 */
final readonly class Pain001Payment
{
    public function __construct(
        public string $creditorName,
        public string $creditorIban,
        public float $amount,
        public string $currency,
        public string $endToEndId,
        public ?string $instructionId = null,
        public ?string $creditorBic = null,
        public ?string $reference = null,
        public ?string $remittanceText = null,
    ) {}
}
