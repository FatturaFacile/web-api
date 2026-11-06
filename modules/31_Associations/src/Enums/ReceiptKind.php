<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum ReceiptKind: string
{
    case Simple = 'simple';
    case Full = 'full';
}
