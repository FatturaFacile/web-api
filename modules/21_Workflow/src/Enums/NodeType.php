<?php

declare(strict_types=1);

namespace Modules\Workflow\Enums;

/**
 * Kinds of node of a workflow graph. The designer speaks these names; the table also keeps the legacy aliases
 * (`conditional`, `fork`, `join`) that older definitions use.
 */
enum NodeType: string
{
    case Start = 'start';
    case Approval = 'approval';
    case ParallelSplit = 'parallel_split';
    case ParallelJoin = 'parallel_join';
    case Condition = 'condition';
    case Notification = 'notification';
    case End = 'end';

    /**
     * @return list<string> every value accepted when saving a node, including legacy aliases
     */
    public static function accepted(): array
    {
        return [...array_map(static fn (self $type): string => $type->value, self::cases()), 'conditional', 'fork', 'join'];
    }
}
