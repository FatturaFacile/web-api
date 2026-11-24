<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A line of the qualification checklist with its weight; a supplier scores from 0 to max_score on it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $weight
 * @property int $max_score
 * @property bool $is_active
 * @property int $sort_order
 */
final class QualificationCriterion extends BaseEnterpriseModel
{
    protected $table = 'sp_qualification_criteria';

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'max_score' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
