<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * An entry of the skill taxonomy. A skill that requires a certification only counts while the employee holds a valid
 * safety certificate (Mod 099) of the type in certification_code.
 *
 * @property string $category
 * @property string $skill_name
 * @property bool $requires_certification
 * @property string|null $certification_code
 */
final class Skill extends BaseEnterpriseModel
{
    protected $table = 'rp_skills';

    protected function casts(): array
    {
        return ['requires_certification' => 'boolean'];
    }
}
