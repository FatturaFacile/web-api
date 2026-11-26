<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $field_name
 * @property string $operator
 * @property ?string $value_string
 * @property ?string $value_numeric
 * @property ?array<int, mixed> $value_json
 * @property ?string $cost_center_id
 */
final class ApprovalMatrixRule extends BaseEnterpriseModel
{
    protected $table = 'approval_matrix_rules';

    protected function casts(): array
    {
        return [
            'value_numeric' => 'decimal:4',
            'value_json' => 'array',
        ];
    }

    public function matrix(): BelongsTo
    {
        return $this->belongsTo(ApprovalMatrix::class, 'approval_matrix_id');
    }
}
