<?php

declare(strict_types=1);

namespace App\Services\SwissQrBill;

use App\Exceptions\QrBillGenerationException;
use App\Models\ContactAddress;
use App\Models\Invoice;
use Illuminate\Support\Str;
use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\QrCode\QrCode;

/**
 * Builds SIX-compliant Swiss QR-bills (sprain/swiss-qr-bill) from invoices.
 *
 * Account and reference follow the SIX rules:
 *  - QR-IBAN  + QR reference (27 digits, modulo 10 recursive check digit)   -> type QRR
 *  - standard IBAN + creditor reference (ISO 11649, "RF" prefix)            -> type SCOR
 *  - standard IBAN without reference                                        -> type NON
 * A stored reference decides the type; without one a QR reference is derived from the invoice number when a
 * QR-IBAN is available, otherwise the bill is issued without reference. The service never writes to the database.
 */
class SwissQrBillService
{
    private const SUPPORTED_CURRENCIES = ['CHF', 'EUR'];

    /**
     * Typographic characters outside the QR-bill character set, mapped to their closest allowed equivalent.
     *
     * @var array<string, string>
     */
    private const CHARACTER_REPLACEMENTS = [
        '—' => '-', '–' => '-', '‘' => "'", '’' => "'", '“' => '"', '”' => '"', '…' => '...', "\u{00A0}" => ' ',
    ];

    public function __construct(private readonly QrBillGeneratorService $helpers) {}

    /**
     * @throws QrBillGenerationException when the invoice data cannot produce a valid QR-bill
     */
    public function generate(Invoice $invoice): GeneratedQrBill
    {
        $invoice->loadMissing(['tenant', 'contact.addresses', 'qrBillData']);

        if ($invoice->tenant === null && $invoice->tenant_id) {
            $invoice->setRelation('tenant', \App\Models\Tenant::find($invoice->tenant_id));
        }

        if ($invoice->contact === null && $invoice->contact_id) {
            $invoice->setRelation('contact', \App\Models\Contact::withoutGlobalScopes()->with('addresses')->find($invoice->contact_id));
        }

        if ($invoice->tenant === null) {
            throw QrBillGenerationException::because(QrBillGenerationException::MISSING_TENANT);
        }

        $currency = strtoupper((string) $invoice->currency);

        if (! in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw QrBillGenerationException::because(QrBillGenerationException::INVALID_CURRENCY);
        }

        $amount = round((float) $invoice->total_gross - (float) $invoice->total_paid, 2);

        if ($amount <= 0) {
            throw QrBillGenerationException::because(QrBillGenerationException::INVALID_AMOUNT);
        }

        [$iban, $referenceType, $reference] = $this->accountAndReference($invoice);
        $creditor = $this->creditorAddress($invoice);
        $debtor = $this->debtorAddress($invoice);
        $message = $this->text(__('qr_bill.additional_message', ['number' => $invoice->invoice_number]), 140);

        $qrBill = QrBill::create();
        $qrBill->setCreditorInformation(CreditorInformation::create($iban));
        $qrBill->setCreditor($this->structuredAddress($creditor));
        $qrBill->setPaymentAmountInformation(PaymentAmountInformation::create($currency, $amount));
        $qrBill->setPaymentReference(PaymentReference::create($referenceType, $reference));
        $qrBill->setAdditionalInformation(AdditionalInformation::create($message));

        if ($debtor !== null) {
            $qrBill->setUltimateDebtor($this->structuredAddress($debtor));
        }

        $qrBill->setUnsupportedCharacterReplacements(self::CHARACTER_REPLACEMENTS);

        if (! $qrBill->isValid()) {
            $violations = [];

            foreach ($qrBill->getViolations() as $violation) {
                $violations[] = $violation->getPropertyPath().': '.$violation->getMessage();
            }

            throw QrBillGenerationException::because(QrBillGenerationException::VALIDATION_FAILED, $violations);
        }

        $qrCode = $qrBill->getQrCode();

        return new GeneratedQrBill(
            payload: $qrCode->getText(),
            svg: $qrCode->getAsString(QrCode::FILE_FORMAT_SVG),
            pngDataUri: $qrCode->getDataUri(QrCode::FILE_FORMAT_PNG),
            referenceType: $referenceType,
            reference: $reference,
            formattedReference: $qrBill->getPaymentReference()->getFormattedReference(),
            iban: $iban,
            formattedIban: $qrBill->getCreditorInformation()->getFormattedIban(),
            currency: $currency,
            amount: $amount,
            formattedAmount: (string) $qrBill->getPaymentAmountInformation()->getFormattedAmount(),
            creditorLines: $this->addressLines($creditor),
            debtorLines: $debtor !== null ? $this->addressLines($debtor) : null,
            message: $message,
        );
    }

    /**
     * Derive a 27-digit QR reference (26 digits plus modulo 10 recursive check digit) from an invoice number.
     */
    public function makeQrReference(string $seed): string
    {
        return $this->helpers->generateScorReference($seed);
    }

    /**
     * Pick the creditor account and the matching reference type and value.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function accountAndReference(Invoice $invoice): array
    {
        $candidates = array_values(array_unique(array_filter(array_map(
            fn (?string $iban): string => strtoupper((string) preg_replace('/\s+/', '', (string) $iban)),
            [$invoice->qrBillData?->qr_iban, $invoice->tenant->qr_iban, $invoice->tenant->iban]
        ))));

        if ($candidates === []) {
            throw QrBillGenerationException::because(QrBillGenerationException::MISSING_CREDITOR_IBAN);
        }

        $qrIban = collect($candidates)->first(fn (string $iban): bool => $this->helpers->isValidQrIban($iban));
        $standardIban = collect($candidates)->first(fn (string $iban): bool => $this->isStandardSwissIban($iban));
        $stored = strtoupper((string) preg_replace('/\s+/', '', (string) $invoice->qrBillData?->scor_reference));

        if ($stored !== '') {
            return $this->storedReference($stored, $qrIban, $standardIban);
        }

        if ($qrIban !== null) {
            $seed = preg_replace('/\D/', '', (string) $invoice->invoice_number) ?: (string) crc32((string) $invoice->id);

            return [$qrIban, PaymentReference::TYPE_QR, $this->makeQrReference($seed)];
        }

        if ($standardIban !== null) {
            return [$standardIban, PaymentReference::TYPE_NON, null];
        }

        throw QrBillGenerationException::because(QrBillGenerationException::INVALID_CREDITOR_IBAN);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function storedReference(string $reference, ?string $qrIban, ?string $standardIban): array
    {
        if (ctype_digit($reference) && strlen($reference) === 27) {
            if (! $this->helpers->validateScorReference($reference)) {
                throw QrBillGenerationException::because(QrBillGenerationException::INVALID_REFERENCE);
            }

            if ($qrIban === null) {
                throw QrBillGenerationException::because(QrBillGenerationException::REFERENCE_MISMATCH);
            }

            return [$qrIban, PaymentReference::TYPE_QR, $reference];
        }

        if (str_starts_with($reference, 'RF')) {
            if ($standardIban === null) {
                throw QrBillGenerationException::because(QrBillGenerationException::REFERENCE_MISMATCH);
            }

            // The ISO 11649 check digits are verified by the library validation.
            return [$standardIban, PaymentReference::TYPE_SCOR, $reference];
        }

        throw QrBillGenerationException::because(QrBillGenerationException::INVALID_REFERENCE);
    }

    private function isStandardSwissIban(string $iban): bool
    {
        return (bool) preg_match('/^(CH|LI)/', $iban)
            && $this->helpers->validateIbanChecksum($iban)
            && ! $this->helpers->isValidQrIban($iban);
    }

    /**
     * The creditor is the issuing company; its address lives in the `company` tenant preferences, either as
     * structured fields (street, house_number, postal_code, city) or as two combined lines
     * (address_line_1 "Street 12", address_line_2 "8001 Zurich").
     *
     * @return array{name: string, street: string, number: ?string, postal_code: string, city: string, country: string}
     */
    private function creditorAddress(Invoice $invoice): array
    {
        $tenant = $invoice->tenant;
        $company = $tenant->settings['preferences']['company'] ?? [];
        $name = $this->text((string) ($tenant->legal_name ?: $tenant->name), 70);

        $address = $this->structuredFields($company) ?? $this->parseCombinedLines((string) ($company['address_line_1'] ?? ''), (string) ($company['address_line_2'] ?? ''));

        if ($name === '' || $address === null) {
            throw QrBillGenerationException::because(QrBillGenerationException::MISSING_CREDITOR_ADDRESS);
        }

        return [
            'name' => $name,
            'street' => $address['street'],
            'number' => $address['number'],
            'postal_code' => $address['postal_code'],
            'city' => $address['city'],
            'country' => strtoupper((string) ($company['country'] ?? $tenant->country ?: 'CH')),
        ];
    }

    /**
     * The debtor is optional on a QR-bill: it is printed only when the customer has a complete billing address.
     *
     * @return array{name: string, street: string, number: ?string, postal_code: string, city: string, country: string}|null
     */
    private function debtorAddress(Invoice $invoice): ?array
    {
        $contact = $invoice->contact;
        $address = $contact?->addresses->firstWhere('type', 'billing') ?? $contact?->addresses->first();

        if (! $address instanceof ContactAddress) {
            return null;
        }

        $name = $this->text((string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name)), 70);
        $fields = $this->structuredFields([
            'street' => $address->street,
            'house_number' => $address->house_number,
            'postal_code' => $address->postal_code,
            'city' => $address->city,
        ]);

        if ($name === '' || $fields === null) {
            return null;
        }

        return $fields + ['name' => $name, 'country' => strtoupper((string) ($address->country ?: $contact->country))];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{street: string, number: ?string, postal_code: string, city: string}|null
     */
    private function structuredFields(array $source): ?array
    {
        $street = $this->text((string) ($source['street'] ?? ''), 70);
        $postalCode = $this->text((string) ($source['postal_code'] ?? ''), 16);
        $city = $this->text((string) ($source['city'] ?? ''), 35);
        $number = $this->text((string) ($source['house_number'] ?? ''), 16);

        if ($street === '' || $postalCode === '' || $city === '') {
            return null;
        }

        return ['street' => $street, 'number' => $number !== '' ? $number : null, 'postal_code' => $postalCode, 'city' => $city];
    }

    /**
     * Convert a combined address (two free-text lines) into structured fields.
     *
     * @return array{street: string, number: ?string, postal_code: string, city: string}|null
     */
    private function parseCombinedLines(string $line1, string $line2): ?array
    {
        if (! preg_match('/^(?<postal_code>[A-Za-z0-9-]{3,16})\s+(?<city>.+)$/u', trim($line2), $place)) {
            return null;
        }

        $street = trim($line1);
        $number = null;

        if (preg_match('/^(?<street>.+?)\s+(?<number>\d+[A-Za-z]?(?:[-\/]\d+[A-Za-z]?)?)$/u', $street, $parts)) {
            $street = $parts['street'];
            $number = $parts['number'];
        }

        return $this->structuredFields([
            'street' => $street,
            'house_number' => $number,
            'postal_code' => $place['postal_code'],
            'city' => $place['city'],
        ]);
    }

    /**
     * @param  array{name: string, street: string, number: ?string, postal_code: string, city: string, country: string}  $address
     */
    private function structuredAddress(array $address): StructuredAddress
    {
        return StructuredAddress::createWithStreet($address['name'], $address['street'], $address['number'], $address['postal_code'], $address['city'], $address['country']);
    }

    /**
     * @param  array{name: string, street: string, number: ?string, postal_code: string, city: string, country: string}  $address
     * @return array<int, string>
     */
    private function addressLines(array $address): array
    {
        return [
            $address['name'],
            trim($address['street'].' '.$address['number']),
            trim($address['postal_code'].' '.$address['city']),
        ];
    }

    /**
     * Normalise free text for the QR-bill: typographic characters are replaced, characters outside the SIX character
     * set are transliterated (or dropped), whitespace is collapsed and the value is cut to the field length.
     */
    private function text(string $value, int $maxLength): string
    {
        $value = strtr($value, self::CHARACTER_REPLACEMENTS);
        $value = (string) preg_replace_callback(
            '/[^'.QrCode::SUPPORTED_CHARACTERS.']/u',
            fn (array $match): string => (string) preg_replace('/[^\x20-\x7E]/', '', Str::ascii($match[0])),
            $value
        );
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, $maxLength);
    }
}
