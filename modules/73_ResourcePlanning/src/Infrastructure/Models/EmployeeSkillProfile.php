<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The proficiency (1 novice .. 5 expert) of an employee in a skill, with the date until which it is valid.
 *
 * @property string $employee_id
 * @property string $skill_id
 * @property int $proficiency_level
 * @property CarbonImmutable|null $valid_until_date
 */
final class EmployeeSkillProfile extends BaseEnterpriseModel
{
    protected $table = 'rp_employee_skill_profiles';

    protected function casts(): array
    {
        return ['proficiency_level' => 'integer', 'valid_until_date' => 'immutable_date'];
    }

    /**
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'skill_id');
    }

    /**
     * Still valid on $day (a profile without an end date does not expire).
     */
    public function isValidOn(CarbonImmutable $day): bool
    {
        return $this->valid_until_date === null || $this->valid_until_date->format('Y-m-d') >= $day->format('Y-m-d');
    }
}
