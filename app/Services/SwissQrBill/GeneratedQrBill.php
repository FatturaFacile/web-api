<?php

declare(strict_types=1);

namespace App\Services\SwissQrBill;

/**
 * A validated Swiss QR-bill: the SPC payload, the QR code images and the data printed on the payment slip.
 */
final readonly class GeneratedQrBill
{
    /**
     * @param  string  $referenceType  QRR (QR reference with QR-IBAN), SCOR (ISO 11649 creditor reference) or NON
     * @param  array<int, string>  $creditorLines  name and address lines of the creditor
     * @param  array<int, string>|null  $debtorLines  name and address lines of the debtor, null when not printed
     */
    public function __construct(
        public string $payload,
        public string $svg,
        public string $pngDataUri,
        public string $referenceType,
        public ?string $reference,
        public ?string $formattedReference,
        public string $iban,
        public string $formattedIban,
        public string $currency,
        public float $amount,
        public string $formattedAmount,
        public array $creditorLines,
        public ?array $debtorLines,
        public ?string $message,
    ) {}

    /**
     * Data consumed by the payment slip template.
     *
     * @return array<string, mixed>
     */
    public function toViewArray(): array
    {
        return [
            'image' => $this->pngDataUri,
            'iban' => $this->formattedIban,
            'reference_type' => $this->referenceType,
            'reference' => $this->formattedReference,
            'currency' => $this->currency,
            'amount' => $this->formattedAmount,
            'creditor_lines' => $this->creditorLines,
            'debtor_lines' => $this->debtorLines,
            'message' => $this->message,
        ];
    }
}
