<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ApprovalMatrix extends BaseEnterpriseModel
{
    protected $table = 'approval_matrixes';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ApprovalMatrixRule::class, 'approval_matrix_id');
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(ApprovalMatrixTier::class, 'approval_matrix_id')->orderBy('tier_level');
    }
}
