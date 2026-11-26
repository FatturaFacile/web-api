<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $target_entity_type
 * @property int $priority
 * @property bool $is_default
 * @property bool $is_active
 * @property ?string $currency
 * @property ?string $auto_approve_below
 * @property ?string $fallback_approver_type
 * @property ?string $fallback_approver_value
 * @property-read Collection<int, ApprovalMatrixRule> $rules
 * @property-read Collection<int, ApprovalMatrixTier> $tiers
 */
final class ApprovalMatrix extends BaseEnterpriseModel
{
    protected $table = 'approval_matrixes';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'priority' => 'integer',
            'auto_approve_below' => 'decimal:4',
        ];
    }

    /**
     * @return HasMany<ApprovalMatrixRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(ApprovalMatrixRule::class, 'approval_matrix_id');
    }

    /**
     * @return HasMany<ApprovalMatrixTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(ApprovalMatrixTier::class, 'approval_matrix_id')->orderBy('tier_level');
    }
}
