<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

enum ChargeCategory: string
{
    case Freight = 'freight';
    case Handling = 'handling';
    case Duty = 'duty';
    case ImportVat = 'import_vat';
    case Insurance = 'insurance';
    case Other = 'other';
}
