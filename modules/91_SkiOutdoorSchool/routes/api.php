<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SkiOutdoorSchool\Http\Controllers\CourseController;
use Modules\SkiOutdoorSchool\Http\Controllers\InstructorController;
use Modules\SkiOutdoorSchool\Http\Controllers\LessonController;
use Modules\SkiOutdoorSchool\Http\Controllers\OverviewController;
use Modules\SkiOutdoorSchool\Http\Controllers\PayoutController;
use Modules\SkiOutdoorSchool\Http\Controllers\PlanningController;
use Modules\SkiOutdoorSchool\Http\Controllers\PublicBookingController;
use Modules\SkiOutdoorSchool\Http\Middleware\ResolvePublicSchool;

/*
|--------------------------------------------------------------------------
| Ski schools, alpine guides and outdoor centres (Mod 286 / Issue #532)
|--------------------------------------------------------------------------
*/
Route::prefix('api/v1/ski-school')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:ski_school'])
    ->group(function (): void {
        Route::get('dashboard/stats', [OverviewController::class, 'stats'])->name('ski-school.dashboard.stats');
        Route::get('reference', [OverviewController::class, 'reference'])->name('ski-school.reference');
        Route::put('settings', [OverviewController::class, 'updateSettings'])->name('ski-school.settings.update');

        Route::get('instructors', [InstructorController::class, 'index'])->name('ski-school.instructors.index');
        Route::post('instructors', [InstructorController::class, 'store'])->name('ski-school.instructors.store');
        Route::get('instructors/{id}', [InstructorController::class, 'show'])->whereUuid('id')->name('ski-school.instructors.show');
        Route::put('instructors/{id}', [InstructorController::class, 'update'])->whereUuid('id')->name('ski-school.instructors.update');
        Route::delete('instructors/{id}', [InstructorController::class, 'destroy'])->whereUuid('id')->name('ski-school.instructors.destroy');
        Route::post('instructors/{id}/availability', [InstructorController::class, 'addAvailability'])->whereUuid('id')->name('ski-school.availability.store');
        Route::delete('availability/{id}', [InstructorController::class, 'removeAvailability'])->whereUuid('id')->name('ski-school.availability.destroy');

        Route::get('planning/board', [PlanningController::class, 'board'])->name('ski-school.planning.board');
        Route::get('planning/rotation', [PlanningController::class, 'rotation'])->name('ski-school.planning.rotation');

        Route::get('lessons', [LessonController::class, 'index'])->name('ski-school.lessons.index');
        Route::post('lessons', [LessonController::class, 'store'])->name('ski-school.lessons.store');
        Route::get('lessons/{id}', [LessonController::class, 'show'])->whereUuid('id')->name('ski-school.lessons.show');
        Route::post('lessons/{id}/assign', [LessonController::class, 'assign'])->whereUuid('id')->name('ski-school.lessons.assign');
        Route::put('lessons/{id}/reschedule', [LessonController::class, 'reschedule'])->whereUuid('id')->name('ski-school.lessons.reschedule');
        Route::post('lessons/{id}/cancel', [LessonController::class, 'cancel'])->whereUuid('id')->name('ski-school.lessons.cancel');
        Route::post('lessons/{id}/complete', [LessonController::class, 'complete'])->whereUuid('id')->name('ski-school.lessons.complete');
        Route::put('lessons/{id}/package', [LessonController::class, 'package'])->whereUuid('id')->name('ski-school.lessons.package');
        Route::post('lessons/{id}/invoice', [LessonController::class, 'invoice'])->whereUuid('id')->name('ski-school.lessons.invoice');
        Route::put('package-items/{id}', [LessonController::class, 'updateItem'])->whereUuid('id')->name('ski-school.package-items.update');

        Route::get('level-board', [CourseController::class, 'levelBoard'])->name('ski-school.level-board');
        Route::post('students/transfer', [CourseController::class, 'transfer'])->name('ski-school.students.transfer');
        Route::get('courses', [CourseController::class, 'index'])->name('ski-school.courses.index');
        Route::post('courses', [CourseController::class, 'store'])->name('ski-school.courses.store');
        Route::get('courses/{id}', [CourseController::class, 'show'])->whereUuid('id')->name('ski-school.courses.show');
        Route::put('courses/{id}', [CourseController::class, 'update'])->whereUuid('id')->name('ski-school.courses.update');
        Route::post('courses/{id}/status', [CourseController::class, 'status'])->whereUuid('id')->name('ski-school.courses.status');
        Route::post('courses/{id}/enrollments', [CourseController::class, 'enroll'])->whereUuid('id')->name('ski-school.courses.enroll');
        Route::post('courses/{id}/attendance', [CourseController::class, 'attendance'])->whereUuid('id')->name('ski-school.courses.attendance');
        Route::get('courses/{id}/bibs', [CourseController::class, 'bibs'])->whereUuid('id')->name('ski-school.courses.bibs');
        Route::delete('enrollments/{id}', [CourseController::class, 'withdraw'])->whereUuid('id')->name('ski-school.enrollments.withdraw');
        Route::put('enrollments/{id}/package', [CourseController::class, 'package'])->whereUuid('id')->name('ski-school.enrollments.package');
        Route::post('enrollments/{id}/invoice', [CourseController::class, 'invoice'])->whereUuid('id')->name('ski-school.enrollments.invoice');

        Route::get('payouts/summary', [PayoutController::class, 'summary'])->name('ski-school.payouts.summary');
        Route::get('payout-statements', [PayoutController::class, 'index'])->name('ski-school.statements.index');
        Route::post('payout-statements/generate', [PayoutController::class, 'generate'])->name('ski-school.statements.generate');
        Route::get('payout-statements/{id}', [PayoutController::class, 'show'])->whereUuid('id')->name('ski-school.statements.show');
        Route::post('payout-statements/{id}/issue', [PayoutController::class, 'issue'])->whereUuid('id')->name('ski-school.statements.issue');
        Route::post('payout-statements/{id}/pay', [PayoutController::class, 'pay'])->whereUuid('id')->name('ski-school.statements.pay');
        Route::delete('payout-statements/{id}', [PayoutController::class, 'destroy'])->whereUuid('id')->name('ski-school.statements.destroy');
        Route::get('payout-statements/{id}/pdf', [PayoutController::class, 'pdf'])->whereUuid('id')->name('ski-school.statements.pdf');
    });

// Public booking portal: no sign-in, the slug of the school selects the company (and only when the portal is switched on), throttled by IP.
Route::prefix('api/v1/ski-school/public/{slug}')
    ->middleware(['api', 'locale', 'throttle:'.config('ski_school.public_throttle', '30,1'), ResolvePublicSchool::class])
    ->group(function (): void {
        Route::get('catalogue', [PublicBookingController::class, 'catalogue'])->name('ski-school.public.catalogue');
        Route::get('availability', [PublicBookingController::class, 'availability'])->name('ski-school.public.availability');
        Route::get('courses', [PublicBookingController::class, 'courses'])->name('ski-school.public.courses');
        Route::post('lessons', [PublicBookingController::class, 'bookLesson'])->name('ski-school.public.lessons');
        Route::post('courses/{courseId}/enrollments', [PublicBookingController::class, 'enroll'])->whereUuid('courseId')->name('ski-school.public.enroll');
    });
