<?php

declare(strict_types=1);

namespace Database\Seeders;

use Modules\SkiOutdoorSchool\Models\CourseLevel;

/**
 * Global reference data of the ski schools (Mod 286): the standard technical level taxonomies of the group courses, Swiss Snow League (Snowli,
 * Blue / Red / Black Prince - King - Star) and the Italian FISI / AMSI medals (Bronze, Silver, Gold). The names of the levels, disciplines,
 * languages, statuses and the IT / CH VAT exemption wording are served by the frontend dictionary (SkiSchoolFrontendTranslations) and the
 * backend dictionary (lang/*\/ski_school.php), never stored here. Idempotent: running it again updates the rows by their natural key.
 */
final class SkiSchoolReferenceSeeder extends GlobalDataSeeder
{
    protected function seedGlobalData(): void
    {
        foreach ((array) config('ski_school.levels') as $system => $levels) {
            foreach (array_values($levels) as $index => $level) {
                CourseLevel::query()->updateOrCreate(
                    ['tenant_id' => null, 'system' => $system, 'code' => $level['code']],
                    ['sort_order' => $index + 1, 'color' => $level['color'], 'is_active' => true],
                );
            }
        }
    }
}
