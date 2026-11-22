<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ResourcePlanning\Presentation\Http\Controllers\AllocationController;
use Modules\ResourcePlanning\Presentation\Http\Controllers\CapacityController;
use Modules\ResourcePlanning\Presentation\Http\Controllers\PlanningReportController;
use Modules\ResourcePlanning\Presentation\Http\Controllers\SkillMatrixController;

// resource_planning: project managers, HR, production and the owners plan resources, convert tentative bookings and rebalance
// overloads; the other members read the capacity, the skill matrix and the utilization.
Route::prefix('api/v1/resource-planning')->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:resource_planning'])->group(function (): void {
    Route::get('skills', [SkillMatrixController::class, 'skills'])->name('resource-planning.skills.index');
    Route::post('skills', [SkillMatrixController::class, 'storeSkill'])->name('resource-planning.skills.store');
    Route::put('skills/{id}', [SkillMatrixController::class, 'updateSkill'])->whereUuid('id')->name('resource-planning.skills.update');
    Route::delete('skills/{id}', [SkillMatrixController::class, 'destroySkill'])->whereUuid('id')->name('resource-planning.skills.destroy');

    Route::get('employee-skill-profiles', [SkillMatrixController::class, 'profiles'])->name('resource-planning.profiles.index');
    Route::post('employee-skill-profiles', [SkillMatrixController::class, 'storeProfile'])->name('resource-planning.profiles.store');
    Route::put('employee-skill-profiles/{id}', [SkillMatrixController::class, 'updateProfile'])->whereUuid('id')->name('resource-planning.profiles.update');
    Route::delete('employee-skill-profiles/{id}', [SkillMatrixController::class, 'destroyProfile'])->whereUuid('id')->name('resource-planning.profiles.destroy');

    Route::get('resources', [CapacityController::class, 'resources'])->name('resource-planning.resources.index');

    Route::get('allocations', [AllocationController::class, 'index'])->name('resource-planning.allocations.index');
    Route::post('allocations', [AllocationController::class, 'store'])->name('resource-planning.allocations.store');
    Route::post('allocations/{id}/convert-to-hard', [AllocationController::class, 'convert'])->whereUuid('id')->name('resource-planning.allocations.convert');
    Route::post('allocations/{id}/rebalance', [AllocationController::class, 'rebalance'])->whereUuid('id')->name('resource-planning.allocations.rebalance');
    Route::delete('allocations/{id}', [AllocationController::class, 'destroy'])->whereUuid('id')->name('resource-planning.allocations.destroy');

    Route::get('capacity/heatmap', [CapacityController::class, 'heatmap'])->name('resource-planning.capacity.heatmap');
    Route::get('capacity/snapshots', [CapacityController::class, 'snapshots'])->name('resource-planning.capacity.snapshots');
    Route::post('capacity/snapshots/recalculate', [CapacityController::class, 'recalculate'])->name('resource-planning.capacity.recalculate');
    Route::post('candidates/search', [CapacityController::class, 'candidates'])->name('resource-planning.candidates.search');

    Route::get('reports/billable-utilization', [PlanningReportController::class, 'utilization'])->name('resource-planning.reports.utilization');
    Route::get('utilization-targets', [PlanningReportController::class, 'targets'])->name('resource-planning.targets.index');
    Route::put('utilization-targets', [PlanningReportController::class, 'setTarget'])->name('resource-planning.targets.set');

    Route::get('holidays', [PlanningReportController::class, 'holidays'])->name('resource-planning.holidays.index');
    Route::get('local-holidays', [PlanningReportController::class, 'localHolidays'])->name('resource-planning.local-holidays.index');
    Route::post('local-holidays', [PlanningReportController::class, 'storeLocalHoliday'])->name('resource-planning.local-holidays.store');
    Route::delete('local-holidays/{id}', [PlanningReportController::class, 'destroyLocalHoliday'])->whereUuid('id')->name('resource-planning.local-holidays.destroy');
});
