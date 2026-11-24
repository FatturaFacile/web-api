<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

/**
 * Edits a node of the WBS (only the given fields change); `parent_node_id` / `sort_order` move it.
 */
final class UpdateWbsNodeRequest extends StoreWbsNodeRequest
{
    protected function presenceRule(): string
    {
        return 'sometimes';
    }
}
