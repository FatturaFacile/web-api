<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Modules\SkiOutdoorSchool\Domain\Enums\LevelSystem;

/**
 * Technical level of a group course (Swiss Snow League, FISI / AMSI or custom). Global rows (tenant_id null) are the standard taxonomy.
 *
 * @property LevelSystem $system
 * @property string $code
 * @property int $sort_order
 * @property string $color
 * @property bool $is_active
 */
final class CourseLevel extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'group_course_levels';

    protected function casts(): array
    {
        return [
            'system' => LevelSystem::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Key of the frontend dictionary entry that names the level. */
    public function labelKey(): string
    {
        return 'skiSchool.level.'.$this->system->value.'.'.$this->code;
    }
}
