<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TalentLearning\Http\Controllers\BudgetPoolController;
use Modules\TalentLearning\Http\Controllers\CertificateController;
use Modules\TalentLearning\Http\Controllers\CompCycleController;
use Modules\TalentLearning\Http\Controllers\ComplianceController;
use Modules\TalentLearning\Http\Controllers\CourseController;
use Modules\TalentLearning\Http\Controllers\DashboardController;
use Modules\TalentLearning\Http\Controllers\EnrolmentController;
use Modules\TalentLearning\Http\Controllers\LearningPathController;
use Modules\TalentLearning\Http\Controllers\LookupController;
use Modules\TalentLearning\Http\Controllers\MeritProposalController;
use Modules\TalentLearning\Http\Controllers\PlanningDashboardController;
use Modules\TalentLearning\Http\Controllers\SuccessionPositionController;
use Modules\TalentLearning\Http\Controllers\SuccessorCandidateController;
use Modules\TalentLearning\Http\Controllers\SurveyController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Succession planning, compensation planning and the pickers of employees and positions. They hold pay data and
| succession plans, so module.access:talent_planning is for owners, administrators, HR and payroll; the policy keeps the
| records inside the company and reserves the approval of merit proposals.
*/
Route::prefix('api/v1/talent-learning')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:talent_planning'])
    ->name('api.v1.talent-learning.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/planning/dashboard', [PlanningDashboardController::class, 'summary'])->name('planning.dashboard');
        Route::get('/lookups/employees', [LookupController::class, 'employees'])->name('lookups.employees');
        Route::get('/lookups/positions', [LookupController::class, 'positions'])->name('lookups.positions');

        Route::get('/succession/positions', [SuccessionPositionController::class, 'index'])->name('succession.positions.index');
        Route::post('/succession/positions', [SuccessionPositionController::class, 'store'])->name('succession.positions.store');
        Route::get('/succession/positions/{id}', [SuccessionPositionController::class, 'show'])->name('succession.positions.show');
        Route::put('/succession/positions/{id}', [SuccessionPositionController::class, 'update'])->name('succession.positions.update');
        Route::delete('/succession/positions/{id}', [SuccessionPositionController::class, 'destroy'])->name('succession.positions.destroy');
        Route::post('/succession/positions/{id}/candidates', [SuccessorCandidateController::class, 'store'])->name('succession.candidates.store');
        Route::put('/succession/candidates/{id}', [SuccessorCandidateController::class, 'update'])->name('succession.candidates.update');
        Route::delete('/succession/candidates/{id}', [SuccessorCandidateController::class, 'destroy'])->name('succession.candidates.destroy');

        Route::get('/compensation/cycles', [CompCycleController::class, 'index'])->name('compensation.cycles.index');
        Route::post('/compensation/cycles', [CompCycleController::class, 'store'])->name('compensation.cycles.store');
        Route::get('/compensation/cycles/{id}', [CompCycleController::class, 'show'])->name('compensation.cycles.show');
        Route::put('/compensation/cycles/{id}', [CompCycleController::class, 'update'])->name('compensation.cycles.update');
        Route::delete('/compensation/cycles/{id}', [CompCycleController::class, 'destroy'])->name('compensation.cycles.destroy');
        Route::post('/compensation/cycles/{id}/open', [CompCycleController::class, 'open'])->name('compensation.cycles.open');
        Route::post('/compensation/cycles/{id}/close', [CompCycleController::class, 'close'])->name('compensation.cycles.close');
        Route::put('/compensation/cycles/{id}/guidelines', [CompCycleController::class, 'guidelines'])->name('compensation.cycles.guidelines');
        Route::get('/compensation/cycles/{id}/summary', [CompCycleController::class, 'summary'])->name('compensation.cycles.summary');

        Route::get('/compensation/pools', [BudgetPoolController::class, 'index'])->name('compensation.pools.index');
        Route::post('/compensation/pools', [BudgetPoolController::class, 'store'])->name('compensation.pools.store');
        Route::get('/compensation/pools/{id}', [BudgetPoolController::class, 'show'])->name('compensation.pools.show');
        Route::put('/compensation/pools/{id}', [BudgetPoolController::class, 'update'])->name('compensation.pools.update');
        Route::delete('/compensation/pools/{id}', [BudgetPoolController::class, 'destroy'])->name('compensation.pools.destroy');

        Route::get('/compensation/proposals', [MeritProposalController::class, 'index'])->name('compensation.proposals.index');
        Route::post('/compensation/proposals', [MeritProposalController::class, 'store'])->name('compensation.proposals.store');
        Route::get('/compensation/proposals/{id}', [MeritProposalController::class, 'show'])->name('compensation.proposals.show');
        Route::put('/compensation/proposals/{id}', [MeritProposalController::class, 'update'])->name('compensation.proposals.update');
        Route::delete('/compensation/proposals/{id}', [MeritProposalController::class, 'destroy'])->name('compensation.proposals.destroy');
        Route::post('/compensation/proposals/{id}/submit', [MeritProposalController::class, 'submit'])->name('compensation.proposals.submit');
        Route::post('/compensation/proposals/{id}/approve', [MeritProposalController::class, 'approve'])->name('compensation.proposals.approve');
        Route::post('/compensation/proposals/{id}/reject', [MeritProposalController::class, 'reject'])->name('compensation.proposals.reject');
    });

/*
| Learning and engagement. module.access:talent_learning lets every member read the catalogue and the open surveys and
| only HR roles change anything; the lists that show other employees (enrolments, certificates, compliance, survey
| results, dashboard) are reserved by the policy to people managers. The employee answers surveys and records her own
| progress through the two routes that only need read access.
*/
Route::prefix('api/v1/talent-learning')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:talent_learning'])
    ->name('api.v1.talent-learning.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'summary'])->name('dashboard');

        Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');
        Route::post('/courses', [CourseController::class, 'store'])->name('courses.store');
        Route::get('/courses/{id}', [CourseController::class, 'show'])->name('courses.show');
        Route::put('/courses/{id}', [CourseController::class, 'update'])->name('courses.update');
        Route::delete('/courses/{id}', [CourseController::class, 'destroy'])->name('courses.destroy');
        Route::post('/courses/{id}/publish', [CourseController::class, 'publish'])->name('courses.publish');
        Route::post('/courses/{id}/archive', [CourseController::class, 'archive'])->name('courses.archive');

        Route::get('/learning-paths', [LearningPathController::class, 'index'])->name('learning-paths.index');
        Route::post('/learning-paths', [LearningPathController::class, 'store'])->name('learning-paths.store');
        Route::get('/learning-paths/{id}', [LearningPathController::class, 'show'])->name('learning-paths.show');
        Route::put('/learning-paths/{id}', [LearningPathController::class, 'update'])->name('learning-paths.update');
        Route::delete('/learning-paths/{id}', [LearningPathController::class, 'destroy'])->name('learning-paths.destroy');
        Route::post('/learning-paths/{id}/publish', [LearningPathController::class, 'publish'])->name('learning-paths.publish');
        Route::post('/learning-paths/{id}/archive', [LearningPathController::class, 'archive'])->name('learning-paths.archive');
        Route::post('/learning-paths/{id}/enrol', [LearningPathController::class, 'enrol'])->name('learning-paths.enrol');

        Route::get('/enrolments', [EnrolmentController::class, 'index'])->name('enrolments.index');
        Route::post('/enrolments', [EnrolmentController::class, 'store'])->name('enrolments.store');
        Route::get('/enrolments/{id}', [EnrolmentController::class, 'show'])->name('enrolments.show');
        Route::post('/enrolments/{id}/progress', [EnrolmentController::class, 'progress'])->name('enrolments.progress');
        Route::post('/enrolments/{id}/complete', [EnrolmentController::class, 'complete'])->name('enrolments.complete');
        Route::post('/enrolments/{id}/cancel', [EnrolmentController::class, 'cancel'])->name('enrolments.cancel');

        Route::get('/certificates', [CertificateController::class, 'index'])->name('certificates.index');
        Route::get('/compliance', [ComplianceController::class, 'show'])->name('compliance');

        Route::get('/my/enrolments', [EnrolmentController::class, 'mine'])->name('my.enrolments');
        Route::get('/my/certificates', [CertificateController::class, 'mine'])->name('my.certificates');
        Route::post('/my/enrolments/{id}/progress', [EnrolmentController::class, 'mineProgress'])->name('my.enrolments.progress')->withoutMiddleware('module.access:talent_learning')->middleware('module.access:talent_learning,read');

        Route::get('/surveys', [SurveyController::class, 'index'])->name('surveys.index');
        Route::post('/surveys', [SurveyController::class, 'store'])->name('surveys.store');
        Route::get('/surveys/{id}', [SurveyController::class, 'show'])->name('surveys.show');
        Route::put('/surveys/{id}', [SurveyController::class, 'update'])->name('surveys.update');
        Route::delete('/surveys/{id}', [SurveyController::class, 'destroy'])->name('surveys.destroy');
        Route::post('/surveys/{id}/open', [SurveyController::class, 'open'])->name('surveys.open');
        Route::post('/surveys/{id}/close', [SurveyController::class, 'close'])->name('surveys.close');
        Route::get('/surveys/{id}/results', [SurveyController::class, 'results'])->name('surveys.results');
        Route::post('/surveys/{id}/responses', [SurveyController::class, 'respond'])->name('surveys.respond')->withoutMiddleware('module.access:talent_learning')->middleware('module.access:talent_learning,read');
    });
