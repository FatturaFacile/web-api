<?php

declare(strict_types=1);

namespace Modules\Planning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A published version of an approved plan: a frozen snapshot of its totals, gaps and capacity load. Only the latest
 * publication of the company is current.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $scenario_id
 * @property int $version
 * @property bool $is_current
 * @property array<string, mixed> $snapshot
 * @property string|null $published_by
 * @property Carbon $published_at
 */
final class PlanningPublication extends BaseEnterpriseModel
{
    protected $table = 'planning_publications';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_current' => 'boolean',
            'snapshot' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
