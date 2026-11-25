<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\DTOs;

/**
 * One signature found in a PDF and what the check established about it.
 */
final readonly class PdfSignatureInfo
{
    /**
     * @param  bool  $intact  the CMS signature matches the bytes it covers (nothing changed since it was applied)
     * @param  bool  $coversWholeDocument  the signed ranges reach the end of the file (no later revision)
     */
    public function __construct(
        public int $index,
        public bool $intact,
        public bool $coversWholeDocument,
        public ?string $signerName,
        public ?string $certificateSerial,
        public ?string $certificateSubject,
        public ?string $certificateIssuer,
        public ?string $validFrom,
        public ?string $validTo,
        public ?string $signingTime,
        public string $subFilter,
        public ?string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'intact' => $this->intact,
            'covers_whole_document' => $this->coversWholeDocument,
            'signer_name' => $this->signerName,
            'certificate_serial' => $this->certificateSerial,
            'certificate_subject' => $this->certificateSubject,
            'certificate_issuer' => $this->certificateIssuer,
            'valid_from' => $this->validFrom,
            'valid_to' => $this->validTo,
            'signing_time' => $this->signingTime,
            'sub_filter' => $this->subFilter,
            'reason' => $this->reason,
        ];
    }
}
