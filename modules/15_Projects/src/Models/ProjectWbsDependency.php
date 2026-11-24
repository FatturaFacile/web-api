<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Precedence link between two WBS nodes of a project: type FS, SS, FF or SF with a lag in calendar days (negative = lead).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property string $predecessor_node_id
 * @property string $successor_node_id
 * @property string $dependency_type
 * @property int $lag_days
 */
final class ProjectWbsDependency extends BaseEnterpriseModel
{
    protected $table = 'project_wbs_dependencies';

    protected function casts(): array
    {
        return ['lag_days' => 'integer'];
    }

    /**
     * @return BelongsTo<ProjectWbsNode, $this>
     */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(ProjectWbsNode::class, 'predecessor_node_id');
    }

    /**
     * @return BelongsTo<ProjectWbsNode, $this>
     */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(ProjectWbsNode::class, 'successor_node_id');
    }
}
