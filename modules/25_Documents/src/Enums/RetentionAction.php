<?php

declare(strict_types=1);

namespace Modules\Documents\Enums;

enum RetentionAction: string
{
    case Archive = 'archive';
    case Delete = 'delete';
}
