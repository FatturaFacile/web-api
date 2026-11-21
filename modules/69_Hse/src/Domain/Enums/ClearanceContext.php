<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Enums;

/**
 * Where a worker is about to be dispatched: the production floor (Mod 064), an external service call (Mod 084) or a
 * construction site (Mod 108).
 */
enum ClearanceContext: string
{
    case ProductionWorkCenter = 'production_work_center';
    case ExternalFieldService = 'external_field_service';
    case ConstructionSite = 'construction_site';
}
