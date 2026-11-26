<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property int $lock_version
 * @property array<string, mixed> $configuration
 */
final class WorkflowDefinition extends BaseEnterpriseModel
{
    protected $table = 'workflow_definitions';

    /**
     * Calculated columns of the grid (portable SQL, see App\Support\Http\GridColumns).
     *
     * `activity` is the readable state of the boolean `is_active` flag (`active` / `inactive`), the same on every database.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'activity' => "CASE WHEN workflow_definitions.is_active THEN 'active' ELSE 'inactive' END",
        ];
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'configuration' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WorkflowNode, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(WorkflowNode::class, 'workflow_definition_id');
    }

    /**
     * @return HasMany<WorkflowTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'workflow_definition_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'workflow_definition_id');
    }

    public function getStartNode(): ?WorkflowNode
    {
        return $this->nodes()->where('node_type', 'start')->first();
    }
}
