<?php

declare(strict_types=1);

namespace Modules\Projects\Wbs\Enums;

/**
 * Workflow column of a WBS node on the Kanban board.
 */
enum WbsNodeStatus: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Done = 'done';
    case Blocked = 'blocked';
}
