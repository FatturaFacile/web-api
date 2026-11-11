<?php

declare(strict_types=1);

namespace Modules\Quality\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An internal (process or supplier) audit: planned, performed against a checklist of requirements, with findings.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $title
 * @property string|null $scope
 * @property string $audit_type
 * @property Carbon $planned_date
 * @property Carbon|null $performed_date
 * @property string|null $lead_auditor_id
 * @property string|null $auditee
 * @property string $status
 * @property string|null $summary
 */
final class QmsAudit extends BaseEnterpriseModel
{
    public const array Types = ['internal', 'process', 'supplier'];

    protected $table = 'qms_audits';

    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'performed_date' => 'date',
        ];
    }

    /**
     * @return HasMany<QmsAuditChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(QmsAuditChecklistItem::class, 'audit_id')->orderBy('position');
    }

    /**
     * @return HasMany<QmsAuditFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(QmsAuditFinding::class, 'audit_id')->orderBy('number');
    }
}
