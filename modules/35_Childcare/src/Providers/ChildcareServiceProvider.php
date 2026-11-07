<?php

declare(strict_types=1);

namespace Modules\Childcare\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Childcare\Models\Activity;
use Modules\Childcare\Models\Attendance;
use Modules\Childcare\Models\AttendanceCorrection;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\ChildDocument;
use Modules\Childcare\Models\ChildFee;
use Modules\Childcare\Models\DocumentRequirement;
use Modules\Childcare\Models\FeePlan;
use Modules\Childcare\Models\Group;
use Modules\Childcare\Models\Message;
use Modules\Childcare\Models\MonthlyBill;
use Modules\Childcare\Models\Note;
use Modules\Childcare\Models\ReferencePerson;
use Modules\Childcare\Models\StaffShift;
use Modules\Childcare\Models\SubsidyRule;
use Modules\Childcare\Models\WeeklyPlan;
use Modules\Childcare\Policies\ChildcarePolicy;

final class ChildcareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/childcare.php', 'childcare');
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([
            Activity::class, Attendance::class, AttendanceCorrection::class, Child::class, ChildDocument::class, ChildFee::class, DocumentRequirement::class,
            FeePlan::class, Group::class, Message::class, MonthlyBill::class, Note::class, ReferencePerson::class, StaffShift::class, SubsidyRule::class, WeeklyPlan::class,
        ] as $model) {
            Gate::policy($model, ChildcarePolicy::class);
        }
    }
}
