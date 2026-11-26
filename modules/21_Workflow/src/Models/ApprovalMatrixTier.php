<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $tier_level
 * @property string $approver_type
 * @property string $approver_value
 * @property string $approval_type
 * @property ?string $threshold_min
 * @property ?string $threshold_max
 * @property bool $threshold_min_inclusive
 */
final class ApprovalMatrixTier extends BaseEnterpriseModel
{
    protected $table = 'approval_matrix_tiers';

    protected function casts(): array
    {
        return [
            'tier_level' => 'integer',
            'threshold_min_inclusive' => 'boolean',
            'threshold_min' => 'decimal:4',
            'threshold_max' => 'decimal:4',
        ];
    }

    public function matrix(): BelongsTo
    {
        return $this->belongsTo(ApprovalMatrix::class, 'approval_matrix_id');
    }
}
