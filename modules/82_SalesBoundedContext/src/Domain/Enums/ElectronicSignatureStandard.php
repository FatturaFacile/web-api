<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum ElectronicSignatureStandard: string
{
    case EidasFes = 'eidas_fes';
    case EidasFea = 'eidas_fea';
    case EidasFeq = 'eidas_feq';
    case ZertesQes = 'zertes_qes';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
