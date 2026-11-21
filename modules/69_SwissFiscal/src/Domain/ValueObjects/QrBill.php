<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\ValueObjects;

use Modules\SwissFiscal\Domain\Enums\QrReferenceType;
use Modules\SwissFiscal\Domain\Exceptions\SwissFiscalException;
use Modules\SwissFiscal\Domain\Services\PaymentReference;
use Modules\SwissFiscal\Domain\Services\SwissIban;

/**
 * A validated Swiss QR-bill (SIX Implementation Guidelines v2.x). Construction enforces every rule that decides
 * whether a bank will accept the slip: IBAN family vs reference type, currency, amount range and field lengths.
 */
final readonly class QrBill
{
    public string $iban;

    public ?string $reference;

    public function __construct(
        string $iban,
        public QrBillAddress $creditor,
        public Currency $currency,
        public ?Money $amount,
        public QrReferenceType $referenceType,
        ?string $reference = null,
        public ?QrBillAddress $debtor = null,
        public string $unstructuredMessage = '',
        public string $billInformation = '',
    ) {
        $this->iban = SwissIban::normalize($iban);
        $this->reference = $reference === null ? null : strtoupper(str_replace(' ', '', $reference));

        $this->validate();
    }

    private function validate(): void
    {
        if (! $this->currency->isQrBillCurrency()) {
            throw SwissFiscalException::invalidQrBill('only CHF and EUR are allowed');
        }

        if (! SwissIban::isValid($this->iban)) {
            throw SwissFiscalException::invalidQrBill('the creditor IBAN must be a valid CH or LI IBAN');
        }

        if ($this->amount instanceof Money) {
            if ($this->amount->currency !== $this->currency) {
                throw SwissFiscalException::currencyMismatch($this->amount->currency->value, $this->currency->value);
            }

            if ($this->amount->minor < 1 || $this->amount->minor > 99_999_999_999) {
                throw SwissFiscalException::invalidQrBill('the amount must be between 0.01 and 999999999.99');
            }
        }

        $this->validateReference();

        if (mb_strlen($this->unstructuredMessage) + mb_strlen($this->billInformation) > 140) {
            throw SwissFiscalException::invalidQrBill('message and billing information together exceed 140 characters');
        }
    }

    private function validateReference(): void
    {
        $isQrIban = SwissIban::isQrIban($this->iban);

        match ($this->referenceType) {
            QrReferenceType::QrReference => $this->assertQrReference($isQrIban),
            QrReferenceType::CreditorReference => $this->assertCreditorReference($isQrIban),
            QrReferenceType::None => $this->assertNoReference($isQrIban),
        };
    }

    private function assertQrReference(bool $isQrIban): void
    {
        if (! $isQrIban) {
            throw SwissFiscalException::invalidQrBill('a QR reference requires a QR-IBAN');
        }

        if ($this->reference === null || ! PaymentReference::isValidQrReference($this->reference)) {
            throw SwissFiscalException::invalidQrBill('the QR reference must have 27 digits and a valid check digit');
        }
    }

    private function assertCreditorReference(bool $isQrIban): void
    {
        if ($isQrIban) {
            throw SwissFiscalException::invalidQrBill('a creditor reference (SCOR) requires a regular IBAN, not a QR-IBAN');
        }

        if ($this->reference === null || ! PaymentReference::isValidCreditorReference($this->reference)) {
            throw SwissFiscalException::invalidQrBill('the creditor reference must be a valid ISO 11649 reference');
        }
    }

    private function assertNoReference(bool $isQrIban): void
    {
        if ($isQrIban) {
            throw SwissFiscalException::invalidQrBill('a QR-IBAN requires a QR reference');
        }

        if ($this->reference !== null && $this->reference !== '') {
            throw SwissFiscalException::invalidQrBill('reference type NON must not carry a reference');
        }
    }
}
