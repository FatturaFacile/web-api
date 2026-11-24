<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\Enums;

/**
 * Consideration basis (Art. 39 LIVA): agreed (vereinbart / fatturato) or received (vereinnahmt / incassato).
 */
enum SwissVatConsiderationBasis: string
{
    case Agreed = 'agreed';
    case Received = 'received';
}
