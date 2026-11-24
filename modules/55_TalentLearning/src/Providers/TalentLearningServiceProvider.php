<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\TalentLearning\Contracts\HrDirectory;
use Modules\TalentLearning\Models\CompBudgetPool;
use Modules\TalentLearning\Models\CompCycle;
use Modules\TalentLearning\Models\CompProposal;
use Modules\TalentLearning\Models\EngagementSurvey;
use Modules\TalentLearning\Models\LmsCertificate;
use Modules\TalentLearning\Models\LmsCourse;
use Modules\TalentLearning\Models\LmsEnrolment;
use Modules\TalentLearning\Models\LmsLearningPath;
use Modules\TalentLearning\Models\SuccessionCandidate;
use Modules\TalentLearning\Models\SuccessionPosition;
use Modules\TalentLearning\Policies\TalentLearningPolicy;
use Modules\TalentLearning\Services\EloquentHrDirectory;

/**
 * Registers the routes, the policy and the HR directory of the Talent and learning module.
 */
final class TalentLearningServiceProvider extends ServiceProvider
{
    /**
     * Register the bindings of the module.
     */
    public function register(): void
    {
        $this->app->bind(HrDirectory::class, EloquentHrDirectory::class);
    }

    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([
            SuccessionPosition::class,
            SuccessionCandidate::class,
            CompCycle::class,
            CompBudgetPool::class,
            CompProposal::class,
            LmsCourse::class,
            LmsLearningPath::class,
            LmsEnrolment::class,
            LmsCertificate::class,
            EngagementSurvey::class,
        ] as $model) {
            Gate::policy($model, TalentLearningPolicy::class);
        }
    }
}
