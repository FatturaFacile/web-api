<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum ApprovalMode: string
{
    case Automatic = 'automatic';
    case Board = 'board';
}
