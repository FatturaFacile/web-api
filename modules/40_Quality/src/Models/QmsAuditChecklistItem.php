<?php

declare(strict_types=1);

namespace Modules\Quality\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One requirement of an audit checklist and the result recorded by the auditor.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $audit_id
 * @property int $position
 * @property string|null $clause
 * @property string $requirement
 * @property string $result
 * @property string|null $notes
 */
final class QmsAuditChecklistItem extends BaseEnterpriseModel
{
    public const array Results = ['pending', 'conforming', 'nonconforming', 'observation', 'not_applicable'];

    protected $table = 'qms_audit_checklist_items';

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<QmsAudit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(QmsAudit::class, 'audit_id');
    }
}
