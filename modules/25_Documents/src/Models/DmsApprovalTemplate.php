<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable approval route: ordered steps, each decided by a user or by any member with a role.
 *
 * @property string $id
 * @property string $name
 * @property ?string $document_type
 * @property bool $is_active
 */
final class DmsApprovalTemplate extends BaseEnterpriseModel
{
    protected $table = 'dms_approval_templates';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<DmsApprovalTemplateStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(DmsApprovalTemplateStep::class, 'template_id')->orderBy('position');
    }
}
