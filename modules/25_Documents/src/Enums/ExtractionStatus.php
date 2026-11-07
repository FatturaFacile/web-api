<?php

declare(strict_types=1);

namespace Modules\Documents\Enums;

enum ExtractionStatus: string
{
    case Proposed = 'proposed';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
