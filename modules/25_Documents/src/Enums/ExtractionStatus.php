<?php

declare(strict_types=1);

namespace Modules\Documents\Enums;

/**
 * Allowed values of extraction status in the Documents module.
 */
enum ExtractionStatus: string
{
    case Proposed = 'proposed';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
