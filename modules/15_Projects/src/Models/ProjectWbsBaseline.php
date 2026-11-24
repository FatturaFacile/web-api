<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen copy of the WBS of a project (nodes with dates, effort, cost and revenue) to measure schedule and budget
 * variance against.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property int $baseline_number
 * @property string $name
 * @property CarbonInterface $captured_at
 * @property string|null $captured_by
 * @property array<string, mixed> $snapshot_json
 */
final class ProjectWbsBaseline extends BaseEnterpriseModel
{
    protected $table = 'project_wbs_baselines';

    protected function casts(): array
    {
        return [
            'baseline_number' => 'integer',
            'captured_at' => 'datetime',
            'snapshot_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
