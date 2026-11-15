<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The merit increase range, in percent of the salary, recommended for a performance rating.
 *
 * @property string $id
 * @property string $cycle_id
 * @property int $performance_rating
 * @property string $min_percent
 * @property string $max_percent
 */
final class CompGuideline extends BaseEnterpriseModel
{
    protected $table = 'tl_comp_guidelines';

    protected function casts(): array
    {
        return ['performance_rating' => 'integer', 'min_percent' => 'decimal:2', 'max_percent' => 'decimal:2'];
    }
}
