<?php

declare(strict_types=1);

namespace Modules\Esterometro\Domain\Enums;

/**
 * FatturaPA self-invoice & integration document types for Italian Esterometro & Reverse Charge (TD16-TD28).
 */
enum SelfInvoiceDocumentType: string
{
    case TD16 = 'TD16';
    case TD17 = 'TD17';
    case TD18 = 'TD18';
    case TD19 = 'TD19';
    case TD20 = 'TD20';
    case TD28 = 'TD28';

    public function description(): string
    {
        return match ($this) {
            self::TD16 => 'Integrazione fattura reverse charge interno',
            self::TD17 => "Integrazione/autofattura per acquisto servizi dall'estero",
            self::TD18 => 'Integrazione per acquisto di beni intracomunitari',
            self::TD19 => 'Integrazione/autofattura per acquisto di beni ex art.17 c.2 DPR 633/72',
            self::TD20 => 'Autofattura per regolarizzazione e integrazione delle fatture (art.6 c.8 d.lgs. 471/97)',
            self::TD28 => 'Acquisti da San Marino con IVA / senza addebito d\'imposta',
        };
    }

    public function legalReference(): string
    {
        return match ($this) {
            self::TD16 => 'Art. 17 c.5-6 DPR 633/72 (Reverse charge interno)',
            self::TD17 => 'Art. 17 c.2 / Art. 7-ter DPR 633/72 (Servizi da soggetto estero)',
            self::TD18 => 'Art. 38 DL 331/93 (Acquisto intracomunitario di beni)',
            self::TD19 => 'Art. 17 c.2 DPR 633/72 (Beni già in Italia da soggetto estero)',
            self::TD20 => 'Art. 6 c.8 e 9-bis D.Lgs. 471/97 (Regolarizzazione autofattura)',
            self::TD28 => 'DM 21/06/2021 (Acquisti da San Marino)',
        };
    }

    public function defaultTransactionNature(): TransactionNature
    {
        return match ($this) {
            self::TD16 => TransactionNature::DomesticRc,
            self::TD17 => TransactionNature::Services,
            self::TD18 => TransactionNature::Goods,
            self::TD19 => TransactionNature::GoodsInItaly,
            self::TD20 => TransactionNature::Regularization,
            self::TD28 => TransactionNature::SanMarino,
        };
    }

    public function soggettoEmittente(): string
    {
        return 'CC';
    }

    public function requiresForeignSupplier(): bool
    {
        return in_array($this, [self::TD17, self::TD18, self::TD19], true);
    }

    public function isSanMarino(): bool
    {
        return $this === self::TD28;
    }

    public function isRegularization(): bool
    {
        return $this === self::TD20;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
