<?php

declare(strict_types=1);

namespace Modules\Quality\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A document-controlled procedure. The text lives in the Documents module and is referenced by document_id only;
 * this record carries the control: version, status, owner, review date and the approval, decided by the Workflow module
 * when the company has an active "quality_procedure_approval" definition.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property int $version
 * @property string $title
 * @property string|null $description
 * @property string|null $document_id
 * @property string|null $owner_id
 * @property string|null $author_id
 * @property string $status
 * @property Carbon|null $effective_date
 * @property Carbon|null $review_due_date
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property string|null $approved_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejected_by
 * @property string|null $rejection_reason
 * @property string|null $workflow_instance_id
 */
final class QmsProcedure extends BaseEnterpriseModel
{
    public const string ApprovalWorkflow = 'quality_procedure_approval';

    protected $table = 'qms_procedures';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_date' => 'date',
            'review_due_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
