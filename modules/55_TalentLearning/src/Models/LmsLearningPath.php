<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An ordered sequence of courses.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string $status
 */
final class LmsLearningPath extends BaseEnterpriseModel
{
    protected $table = 'tl_learning_paths';

    /**
     * @return HasMany<LmsPathCourse, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(LmsPathCourse::class, 'learning_path_id')->orderBy('position');
    }
}
