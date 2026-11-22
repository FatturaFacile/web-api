<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node of the multilevel WBS of a project (phase, deliverable, work package or milestone). Values typed on a node with
 * children are replaced by the roll-up of its children.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property string|null $parent_node_id
 * @property string $wbs_code
 * @property string $title
 * @property string $node_type
 * @property string $status
 * @property string|null $required_role
 * @property string $planned_hours
 * @property string $actual_hours
 * @property string $planned_cost
 * @property string $actual_cost
 * @property string $planned_revenue
 * @property CarbonInterface|null $start_date
 * @property CarbonInterface|null $end_date
 * @property string $percent_complete
 * @property int $sort_order
 * @property CarbonInterface|null $early_start
 * @property CarbonInterface|null $early_finish
 * @property CarbonInterface|null $late_start
 * @property CarbonInterface|null $late_finish
 * @property int|null $total_float_days
 * @property int|null $free_float_days
 * @property bool $is_critical
 */
final class ProjectWbsNode extends BaseEnterpriseModel
{
    protected $table = 'project_wbs_nodes';

    protected function casts(): array
    {
        return [
            'planned_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
            'planned_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'planned_revenue' => 'decimal:2',
            'percent_complete' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'early_start' => 'date',
            'early_finish' => 'date',
            'late_start' => 'date',
            'late_finish' => 'date',
            'sort_order' => 'integer',
            'total_float_days' => 'integer',
            'free_float_days' => 'integer',
            'is_critical' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<ProjectWbsNode, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_node_id');
    }

    /**
     * @return HasMany<ProjectWbsNode, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_node_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<ProjectWbsDependency, $this>
     */
    public function predecessors(): HasMany
    {
        return $this->hasMany(ProjectWbsDependency::class, 'successor_node_id');
    }

    /**
     * @return HasMany<ProjectWbsDependency, $this>
     */
    public function successors(): HasMany
    {
        return $this->hasMany(ProjectWbsDependency::class, 'predecessor_node_id');
    }
}
