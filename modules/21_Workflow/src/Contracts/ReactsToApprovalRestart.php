<?php

declare(strict_types=1);

namespace Modules\Workflow\Contracts;

use Modules\Workflow\Models\WorkflowInstance;

/**
 * Optional companion of {@see ApprovableDocument} for documents that must change state when an approved document is edited
 * and its approval restarts: back to "waiting for approval" and locked again.
 */
interface ReactsToApprovalRestart
{
    /**
     * The approval was voided by an edit of a key field and a new instance started.
     */
    public function onApprovalRestarted(WorkflowInstance $instance): void;
}
