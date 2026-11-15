<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of an approval template. `approver_type` is `user` (approver_user_id) or `role` (approver_role).
 *
 * @property string $id
 * @property string $template_id
 * @property int $position
 * @property string $name
 * @property string $approver_type
 * @property ?string $approver_user_id
 * @property ?string $approver_role
 */
final class DmsApprovalTemplateStep extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array ApproverTypes = ['user', 'role'];

    protected $table = 'dms_approval_template_steps';

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<DmsApprovalTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DmsApprovalTemplate::class, 'template_id');
    }
}
