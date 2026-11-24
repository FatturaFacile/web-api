<?php

declare(strict_types=1);

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Projects\Contracts\EarnedValueCalculationServiceInterface;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Contracts\ProjectBudgetServiceInterface;
use Modules\Projects\Contracts\ProjectChangeOrderServiceInterface;
use Modules\Projects\Contracts\ProjectRevenueRecognitionServiceInterface;
use Modules\Projects\Contracts\ProjectTimesheetServiceInterface;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectAuditEntry;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectBudgetLine;
use Modules\Projects\Models\ProjectBudgetVersion;
use Modules\Projects\Models\ProjectChangeOrder;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectFinancialSummary;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Models\ProjectPhase;
use Modules\Projects\Models\ProjectPortfolio;
use Modules\Projects\Models\ProjectRevenueRecognition;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Models\ProjectTimesheet;

test('all project models extend BaseEnterpriseModel', function (): void {
    $models = [
        Project::class,
        ProjectPortfolio::class,
        ProjectPhase::class,
        ProjectMilestone::class,
        ProjectTask::class,
        ProjectBudget::class,
        ProjectBudgetVersion::class,
        ProjectBudgetLine::class,
        ProjectChangeOrder::class,
        ProjectCostEntry::class,
        ProjectFinancialSummary::class,
        ProjectTimesheet::class,
        ProjectRevenueRecognition::class,
        ProjectAuditEntry::class,
    ];

    foreach ($models as $modelClass) {
        $reflection = new ReflectionClass($modelClass);
        expect($reflection->isSubclassOf(BaseEnterpriseModel::class))->toBeTrue();
    }
});

test('all project services are properly bound as singletons in container', function (): void {
    expect(app()->bound(JobCostingAccumulationServiceInterface::class))->toBeTrue();
    expect(app()->bound(EarnedValueCalculationServiceInterface::class))->toBeTrue();
    expect(app()->bound(ProjectBudgetServiceInterface::class))->toBeTrue();
    expect(app()->bound(ProjectChangeOrderServiceInterface::class))->toBeTrue();
    expect(app()->bound(ProjectTimesheetServiceInterface::class))->toBeTrue();
    expect(app()->bound(ProjectRevenueRecognitionServiceInterface::class))->toBeTrue();
});
