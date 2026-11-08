<?php

declare(strict_types=1);

namespace Modules\Documents\Enums;

/**
 * Allowed values of retention action in the Documents module.
 */
enum RetentionAction: string
{
    case Archive = 'archive';
    case Delete = 'delete';
}
