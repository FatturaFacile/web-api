<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\Enums;

/**
 * Foreign service categories subject to Swiss Bezugsteuer (Art. 45 LIVA).
 */
enum BezugsteuerCategory: string
{
    case SoftwareCloud = 'software_cloud';
    case Consulting = 'consulting';
    case ItServices = 'it_services';
    case Telecom = 'telecom';
    case Royalties = 'royalties';
    case Marketing = 'marketing';
    case OtherCrossBorderService = 'other_cross_border_service';
    case ImportService = 'import_service';
}
