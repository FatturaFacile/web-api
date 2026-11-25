<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Audit trail of the transitions of a consolidation run workflow.
 *
 * @property Carbon|null $created_at
 */
final class RunWorkflowEvent extends BaseEnterpriseModel
{
    protected $table = 'ic_run_workflow_events';
}
