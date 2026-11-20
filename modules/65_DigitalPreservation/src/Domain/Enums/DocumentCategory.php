<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Enums;

/**
 * Category of the documents of a batch, mapped to the AgID `TipologiaDocumentale`.
 */
enum DocumentCategory: string
{
    case ActiveInvoices = 'active_invoices';
    case PassiveInvoices = 'passive_invoices';
    case SdiNotifications = 'sdi_notifications';
    case AccountingLedgers = 'accounting_ledgers';
    case TaxDocuments = 'tax_documents';
    case AnalogDematerialized = 'analog_dematerialized';

    /**
     * Default AgID document type of the category (SDI notifications refine it per notification type).
     */
    public function agidType(): string
    {
        return match ($this) {
            self::ActiveInvoices => 'FATTURA_ELETTRONICA_ATTIVA',
            self::PassiveInvoices => 'FATTURA_ELETTRONICA_PASSIVA',
            self::SdiNotifications => 'NOTIFICA_SDI',
            self::AccountingLedgers => 'REGISTRO_CONTABILE',
            self::TaxDocuments => 'REGISTRO_IVA',
            self::AnalogDematerialized => 'DOCUMENTO_ANALOGICO_DEMATERIALIZZATO',
        };
    }

    /**
     * Whether the documents are born digital (false for dematerialized analog documents).
     */
    public function isDigitalNative(): bool
    {
        return $this !== self::AnalogDematerialized;
    }
}
