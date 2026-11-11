<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Enums;

/**
 * Class of documents a preservation package groups. A package holds one class only, so one retention period applies.
 *
 * The suggested retention years are the periods COMMONLY CITED for these documents in Italy (accounting records and
 * invoices: art. 2220 Codice civile, ten years; payslips and the labour book, and contracts: ordinary prescription of
 * ten years, art. 2946 Codice civile, taken as the cautious choice). They were NOT verified against the legal text
 * by this application and MUST be confirmed by the accountant of the company: the tenant edits them in the
 * preservation settings and records the confirmation there. Nothing here is legal advice.
 */
enum PreservationDocumentClass: string
{
    case IssuedInvoice = 'issued_invoice';
    case ReceivedInvoice = 'received_invoice';
    case LedgerBook = 'ledger_book';
    case Payslip = 'payslip';
    case Contract = 'contract';

    /**
     * Commonly cited default, to be confirmed by the accountant. Used only while the tenant has set no period.
     */
    public function suggestedRetentionYears(): int
    {
        return 10;
    }

    /**
     * Type of source the files of this class come from: the stored FatturaPA XML of the invoice, or a file of the
     * document library (ledger books, payslips and contracts are generated or signed elsewhere and filed there).
     */
    public function sourceType(): string
    {
        return match ($this) {
            self::IssuedInvoice => 'invoice',
            self::ReceivedInvoice => 'passive_invoice',
            default => 'dms_document',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $class): string => $class->value, self::cases());
    }
}
