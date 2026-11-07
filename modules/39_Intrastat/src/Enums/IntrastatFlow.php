<?php

declare(strict_types=1);

namespace Modules\Intrastat\Enums;

/**
 * Model INTRA-1 (cessioni di beni e servizi resi, tipo riepilogo "C") or INTRA-2 (acquisti di beni e servizi ricevuti,
 * tipo riepilogo "A"), as in the frontespizio of the technical specifications (Allegato XII, field 6).
 */
enum IntrastatFlow: string
{
    case Sales = 'sales';
    case Purchases = 'purchases';

    public function summaryType(): string
    {
        return $this === self::Sales ? 'C' : 'A';
    }
}
