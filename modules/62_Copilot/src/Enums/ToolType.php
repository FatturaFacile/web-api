<?php

declare(strict_types=1);

namespace Modules\Copilot\Enums;

/**
 * A read query runs at once; a mutating action is only staged until a human confirms it.
 */
enum ToolType: string
{
    case ReadQuery = 'read_query';
    case MutatingAction = 'mutating_action';
}
