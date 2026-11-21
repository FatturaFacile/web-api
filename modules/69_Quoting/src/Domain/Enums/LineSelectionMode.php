<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Enums;

enum LineSelectionMode: string
{
    case Mandatory = 'mandatory';
    case Optional = 'optional';
    case AlternativeGroup = 'alternative_group';
}
