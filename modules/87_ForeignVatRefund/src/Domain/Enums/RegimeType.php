<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Domain\Enums;

/**
 * The legal procedure a foreign VAT refund claim follows.
 */
enum RegimeType: string
{
    case EuDirective200809 = 'EU_DIR_2008_9_CE';
    case ChEstvReciprocity = 'CH_ESTV_RECIPROCITY';
    case ItArt38Ter = 'IT_ART_38_TER';
    case ThirteenthDirective = 'XIII_DIR_86_560_CEE';

    /**
     * Whether the claim is filed through the electronic portal of the Member State of establishment (XML upload).
     */
    public function usesElectronicPortal(): bool
    {
        return $this === self::EuDirective200809;
    }
}
