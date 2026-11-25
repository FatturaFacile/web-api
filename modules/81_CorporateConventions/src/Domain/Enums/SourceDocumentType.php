<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum SourceDocumentType: string
{
    use EnumValues;

    case PosReceipt = 'POS_RECEIPT';
    case Invoice = 'INVOICE';
    case EcommerceOrder = 'ECOMMERCE_ORDER';
}
