<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A language spoken by an instructor (ISO 639-1 code, extensible).
 *
 * @property string $instructor_id
 * @property string $language_code
 * @property string $proficiency
 */
final class InstructorLanguage extends BaseEnterpriseModel
{
    protected $table = 'ski_instructor_languages';
}
