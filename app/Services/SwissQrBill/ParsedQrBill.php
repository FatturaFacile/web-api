<?php

declare(strict_types=1);

namespace App\Services\SwissQrBill;

/**
 * Structured content of a decoded SPC 0200 payload, used to pre-fill supplier invoice drafts.
 */
final readonly class ParsedQrBill
{
    /**
     * @param  array<string, ?string>  $creditor  name, address_type, street, house_number, postal_code, city, country
     * @param  array<string, ?string>|null  $debtor  same shape as the creditor, null when the bill has no debtor
     * @param  array<string, string>  $billingInformation  Swico S1 tags (invoice_number, invoice_date, vat_number, payment_terms)
     * @param  array<int, string>  $errors  machine readable reasons the payload breaks the specification
     */
    public function __construct(
        public string $payload,
        public string $version,
        public string $iban,
        public bool $isQrIban,
        public array $creditor,
        public ?float $amount,
        public string $currency,
        public ?array $debtor,
        public string $referenceType,
        public ?string $reference,
        public ?string $message,
        public ?string $billingInformationRaw,
        public array $billingInformation,
        public bool $valid,
        public array $errors,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'payload' => $this->payload,
            'version' => $this->version,
            'iban' => $this->iban,
            'is_qr_iban' => $this->isQrIban,
            'creditor' => $this->creditor,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'debtor' => $this->debtor,
            'reference_type' => $this->referenceType,
            'reference' => $this->reference,
            'message' => $this->message,
            'billing_information' => $this->billingInformation,
            'valid' => $this->valid,
            'errors' => $this->errors,
            'draft' => [
                'supplier_name' => $this->creditor['name'] ?? null,
                'supplier_iban' => $this->iban,
                'invoice_number' => $this->billingInformation['invoice_number'] ?? null,
                'invoice_date' => $this->billingInformation['invoice_date'] ?? null,
                'vat_number' => $this->billingInformation['vat_number'] ?? null,
                'total_amount' => $this->amount,
                'currency' => $this->currency,
                'payment_reference' => $this->reference,
                'payment_reference_type' => $this->referenceType,
                'notes' => $this->message,
            ],
        ];
    }
}
