<?php

declare(strict_types=1);

namespace Modules\Projects\Wbs\Enums;

/**
 * Precedence relation between two WBS nodes: finish-to-start, start-to-start, finish-to-finish, start-to-finish.
 */
enum DependencyType: string
{
    case FinishToStart = 'FS';
    case StartToStart = 'SS';
    case FinishToFinish = 'FF';
    case StartToFinish = 'SF';
}
