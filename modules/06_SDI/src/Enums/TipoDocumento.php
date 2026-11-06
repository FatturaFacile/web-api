<?php

declare(strict_types=1);

namespace Modules\SDI\Enums;

use Illuminate\Database\Eloquent\Model;

/**
 * TipoDocumentoType of the FatturaPA schema v1.2.3. The descriptions are the ones of the XSD. The party and ledger rules
 * are not in the schema: the report of the change lists the ones an accountant still has to confirm.
 */
enum TipoDocumento: string
{
    case Td01 = 'TD01';
    case Td02 = 'TD02';
    case Td03 = 'TD03';
    case Td04 = 'TD04';
    case Td05 = 'TD05';
    case Td06 = 'TD06';
    case Td16 = 'TD16';
    case Td17 = 'TD17';
    case Td18 = 'TD18';
    case Td19 = 'TD19';
    case Td20 = 'TD20';
    case Td21 = 'TD21';
    case Td22 = 'TD22';
    case Td23 = 'TD23';
    case Td24 = 'TD24';
    case Td25 = 'TD25';
    case Td26 = 'TD26';
    case Td27 = 'TD27';
    case Td28 = 'TD28';
    case Td29 = 'TD29';

    /**
     * The type of a stored document: a credit note is always TD04, an advance TD02, and anything else what it states.
     */
    public static function ofInvoice(Model $invoice): self
    {
        if ($invoice->getAttribute('invoice_type') === 'credit_note') {
            return self::Td04;
        }

        $stated = $invoice->getAttribute('document_type');

        if ($stated !== null && $stated !== '') {
            return self::from((string) $stated);
        }

        return (bool) $invoice->getAttribute('is_deposit') ? self::Td02 : self::Td01;
    }

    public function description(): string
    {
        return match ($this) {
            self::Td01 => 'Fattura',
            self::Td02 => 'Acconto / anticipo su fattura',
            self::Td03 => 'Acconto / anticipo su parcella',
            self::Td04 => 'Nota di credito',
            self::Td05 => 'Nota di debito',
            self::Td06 => 'Parcella',
            self::Td16 => 'Integrazione fattura reverse charge interno',
            self::Td17 => "Integrazione/autofattura per acquisto servizi dall'estero",
            self::Td18 => 'Integrazione per acquisto di beni intracomunitari',
            self::Td19 => 'Integrazione/autofattura per acquisto di beni ex art.17 c.2 DPR 633/72',
            self::Td20 => 'Autofattura per regolarizzazione e integrazione delle fatture',
            self::Td21 => 'Autofattura per splafonamento',
            self::Td22 => 'Estrazione beni da Deposito IVA',
            self::Td23 => "Estrazione beni da Deposito IVA con versamento dell'IVA",
            self::Td24 => "Fattura differita di cui all'art.21, comma 4, terzo periodo lett. a) DPR 633/72",
            self::Td25 => "Fattura differita di cui all'art.21, comma 4, terzo periodo lett. b) DPR 633/72",
            self::Td26 => 'Cessione di beni ammortizzabili e per passaggi interni (ex art.36 DPR 633/72)',
            self::Td27 => 'Fattura per autoconsumo o per cessioni gratuite senza rivalsa',
            self::Td28 => 'Acquisti da San Marino con IVA (fattura cartacea)',
            self::Td29 => 'Comunicazione per omessa o irregolare fatturazione da parte del cedente/prestatore italiano',
        };
    }

    /**
     * Documents the tenant issues as buyer: the supplier is the cedente and the tenant the cessionario.
     */
    public function swapsParties(): bool
    {
        return in_array($this, [self::Td16, self::Td17, self::Td18, self::Td19, self::Td20, self::Td22, self::Td23, self::Td28, self::Td29], true);
    }

    /**
     * Documents where the tenant is both the cedente and the cessionario.
     */
    public function isSameParty(): bool
    {
        return in_array($this, [self::Td21, self::Td27], true);
    }

    /**
     * Documents that record VAT on an operation and never open a receivable.
     */
    public function isSelfInvoice(): bool
    {
        return $this->swapsParties() || $this->isSameParty();
    }

    /**
     * Documents that make no sense without the document they integrate (DatiFattureCollegate).
     */
    public function requiresLinkedDocument(): bool
    {
        return in_array($this, [self::Td16, self::Td17, self::Td18, self::Td19, self::Td20, self::Td21, self::Td22, self::Td23, self::Td28, self::Td29], true);
    }

    /**
     * Documents that can be derived from a received supplier invoice.
     */
    public function canStartFromPassiveInvoice(): bool
    {
        return $this->swapsParties() || $this === self::Td21;
    }

    /**
     * The country limit on the cedente of the document, null when the type sets none.
     *
     * @return array{only?: string, except?: string}|null
     */
    public function supplierCountryRule(): ?array
    {
        return match ($this) {
            self::Td17, self::Td18, self::Td19 => ['except' => 'IT'],
            self::Td28 => ['only' => 'SM'],
            default => null,
        };
    }

    public function ledgerTreatment(): SelfInvoiceLedgerTreatment
    {
        return match ($this) {
            self::Td16, self::Td17, self::Td18, self::Td19, self::Td22 => SelfInvoiceLedgerTreatment::Integration,
            self::Td21, self::Td27 => SelfInvoiceLedgerTreatment::VatCost,
            self::Td20, self::Td23, self::Td28, self::Td29 => SelfInvoiceLedgerTreatment::NoEntry,
            default => SelfInvoiceLedgerTreatment::Sale,
        };
    }

    /**
     * The numbering series of the type: self-invoices have their own, apart from the sales invoices.
     */
    public function numberingSeries(): string
    {
        return $this->isSelfInvoice() ? 'self_invoice' : 'invoice';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
