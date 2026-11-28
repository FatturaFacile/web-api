<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;

/**
 * A discipline an instructor teaches.
 *
 * @property string $instructor_id
 * @property Discipline $discipline
 */
final class InstructorDiscipline extends BaseEnterpriseModel
{
    protected $table = 'ski_instructor_disciplines';

    protected function casts(): array
    {
        return [
            'discipline' => Discipline::class,
        ];
    }
}
