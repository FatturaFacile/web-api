<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\SkiOutdoorSchool\Models\CourseEnrollment;
use Modules\SkiOutdoorSchool\Models\GroupCourse;
use Modules\SkiOutdoorSchool\Models\Instructor;
use Modules\SkiOutdoorSchool\Models\InstructorAvailability;
use Modules\SkiOutdoorSchool\Models\PackageItem;
use Modules\SkiOutdoorSchool\Models\PayoutStatement;
use Modules\SkiOutdoorSchool\Models\SchoolSetting;
use Modules\SkiOutdoorSchool\Models\SkiLesson;
use Modules\SkiOutdoorSchool\Policies\SkiSchoolPolicy;

/**
 * Mod 286 — ski and snowboard schools, alpine guides and outdoor centres: instructor roster, planning board with fair rotation, weekly group
 * courses by level, bundled packages, secretariat retention, payout statements and the public booking engine (Issue #532).
 */
final class SkiOutdoorSchoolServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([Instructor::class, InstructorAvailability::class, SkiLesson::class, GroupCourse::class, CourseEnrollment::class, PackageItem::class, PayoutStatement::class, SchoolSetting::class] as $model) {
            Gate::policy($model, SkiSchoolPolicy::class);
        }
    }
}
