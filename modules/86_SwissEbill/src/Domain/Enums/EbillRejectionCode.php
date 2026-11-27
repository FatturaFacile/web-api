<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Enums;

/**
 * Standard reasons a debtor gives when rejecting an eBill in the e-banking. The text shown to the user is translated
 * (swissEbill.rejection.<code>.title / .hint); an unknown gateway code is kept verbatim and shown as "other".
 */
enum EbillRejectionCode: string
{
    case AmountMismatch = 'AMOUNT_MISMATCH';
    case NotOrdered = 'NOT_ORDERED';
    case Duplicate = 'DUPLICATE';
    case WrongRecipient = 'WRONG_RECIPIENT';
    case PoReferenceMissing = 'PO_REFERENCE_MISSING';
    case VatError = 'VAT_ERROR';
    case DeliveryNotReceived = 'DELIVERY_NOT_RECEIVED';
    case AlreadyPaid = 'ALREADY_PAID';
    case DocumentUnreadable = 'DOCUMENT_UNREADABLE';
    case Other = 'OTHER';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function fromGateway(?string $code): self
    {
        return self::tryFrom(strtoupper(trim((string) $code))) ?? self::Other;
    }
}
