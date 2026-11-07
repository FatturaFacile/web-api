<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

/**
 * Minutes are edited as a draft and become immutable once approved.
 */
enum MinutesStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
}
