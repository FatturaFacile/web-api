<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The annual internal audit plan: a draft is edited, an approved plan commits the audits of the year, a closed plan
 * takes no more audits.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $year
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property Carbon|null $approved_at
 * @property string|null $approved_by
 * @property Carbon|null $closed_at
 */
final class GovAuditPlan extends BaseEnterpriseModel
{
    public const array Statuses = ['draft', 'approved', 'closed'];

    /**
     * Allowed status changes: from => list of targets.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        'draft' => ['approved'],
        'approved' => ['closed'],
        'closed' => [],
    ];

    protected $table = 'gov_audit_plans';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<GovAudit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(GovAudit::class, 'audit_plan_id');
    }
}
