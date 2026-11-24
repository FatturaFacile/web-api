<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\Enums;

/**
 * Input tax adjustment types under Swiss VAT Act (LIVA Art. 30-33).
 */
enum InputTaxAdjustmentType: string
{
    case Autoconsumo = 'autoconsumo';
    case Degrassazione = 'degrassazione';
    case MixedUseReduction = 'mixed_use_reduction';
    case SubsidyReduction = 'subsidy_reduction';

    public function afcBox(): string
    {
        return match ($this) {
            self::Degrassazione => '410',
            self::Autoconsumo, self::MixedUseReduction => '415',
            self::SubsidyReduction => '420',
        };
    }

    /**
     * +1 increases deductible input tax (Box 410), -1 reduces deductible input tax (Boxes 415/420).
     */
    public function sign(): int
    {
        return match ($this) {
            self::Degrassazione => 1,
            self::Autoconsumo, self::MixedUseReduction, self::SubsidyReduction => -1,
        };
    }

    public function legalBasis(): string
    {
        return match ($this) {
            self::Autoconsumo => 'Art. 31 LIVA / MWSTG',
            self::Degrassazione => 'Art. 32 LIVA / MWSTG',
            self::MixedUseReduction => 'Art. 30 LIVA / MWSTG',
            self::SubsidyReduction => 'Art. 33(2) LIVA / MWSTG',
        };
    }
}
