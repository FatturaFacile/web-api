<?php

declare(strict_types=1);

namespace Modules\Quality\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A finding of an audit. A major or minor non-conformity finding raises a non-conformity of source audit.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $audit_id
 * @property string|null $checklist_item_id
 * @property string $number
 * @property string $classification
 * @property string $description
 * @property string|null $nonconformity_id
 * @property string $status
 */
final class QmsAuditFinding extends BaseEnterpriseModel
{
    public const array Classifications = ['major_nc', 'minor_nc', 'observation', 'opportunity'];

    protected $table = 'qms_audit_findings';

    /**
     * @return BelongsTo<QmsAudit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(QmsAudit::class, 'audit_id');
    }

    /**
     * @return BelongsTo<QmsNonconformity, $this>
     */
    public function nonconformity(): BelongsTo
    {
        return $this->belongsTo(QmsNonconformity::class, 'nonconformity_id');
    }
}
