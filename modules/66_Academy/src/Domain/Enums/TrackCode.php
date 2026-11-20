<?php

declare(strict_types=1);

namespace Modules\Academy\Domain\Enums;

/**
 * The learning tracks of the Academy.
 */
enum TrackCode: string
{
    case SwissAccountingKmu = 'swiss-accounting-kmu';
    case ItalianFatturapaAndIva = 'italian-fatturapa-and-iva';
    case WarehouseAndMrpMastery = 'warehouse-and-mrp-mastery';
    case HrAndPayrollChIt = 'hr-and-payroll-ch-it';
    case ErpDeveloperArchitecture = 'erp-developer-architecture';
}
