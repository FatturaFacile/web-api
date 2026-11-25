<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum TenderProcurementFramework: string
{
    case SimapChLapub = 'simap_ch_lapub';
    case AnacMepaDlgs362023 = 'anac_mepa_dlgs36_2023';
    case PrivateRfp = 'private_rfp';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
