<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Modules\HumanResources\Contracts\PerformanceManagementServiceInterface;
use Modules\HumanResources\Contracts\TrainingAndCertificationServiceInterface;
use Modules\HumanResources\DTOs\AssignTrainingDTO;
use Modules\HumanResources\DTOs\SubmitPerformanceReviewDTO;
use Modules\HumanResources\Events\CertificationExpiringEvent;
use Modules\HumanResources\Events\PerformanceReviewCompletedEvent;
use Modules\HumanResources\Events\TrainingAssignedEvent;
use Modules\HumanResources\Events\TrainingBudgetForecastEvent;
use Modules\HumanResources\Events\TrainingCostReadyEvent;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\TrainingCourse;

uses(DatabaseTransactions::class);

test('performance review calculates weighted rating and dispatches PerformanceReviewCompletedEvent upon completion', function () {
    Event::fake([
        PerformanceReviewCompletedEvent::class,
    ]);

    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($tenant);

    $employee = Employee::factory()->active()->create(['tenant_id' => $tenant->id]);

    $service = app(PerformanceManagementServiceInterface::class);
    $review = $service->submitReview(new SubmitPerformanceReviewDTO(
        tenantId: (string) $tenant->id,
        employeeId: (string) $employee->id,
        reviewPeriod: '2026-ANNUAL',
        goalsScore: 4.50,
        competenciesScore: 4.00,
        promotionRecommended: true,
        recommendedSalaryIncreasePercent: 8.50,
        successorReadiness: 'ready_now'
    ));

    // Weighted rating: (4.50 * 0.60) + (4.00 * 0.40) = 2.70 + 1.60 = 4.30
    expect((float) $review->overall_rating)->toBe(4.30)
        ->and($review->status)->toBe('submitted');

    $completed = $service->completeReview($review);
    expect($completed->status)->toBe('completed')
        ->and($completed->completed_at)->not->toBeNull();

    Event::assertDispatched(PerformanceReviewCompletedEvent::class, function (PerformanceReviewCompletedEvent $event) {
        return $event->overallRating === 4.30 && $event->promotionRecommended === true;
    });
});

test('training assignment, completion, certification issuance, and expiring certification scanner dispatch expected events', function () {
    Event::fake([
        TrainingAssignedEvent::class,
        TrainingBudgetForecastEvent::class,
        TrainingCostReadyEvent::class,
        CertificationExpiringEvent::class,
    ]);

    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($tenant);

    $employee = Employee::factory()->active()->create(['tenant_id' => $tenant->id]);
    $course = TrainingCourse::factory()->create([
        'tenant_id' => $tenant->id,
        'course_code' => 'ISO-45001-ADV',
        'title' => 'Advanced ISO 45001 Safety Auditor',
        'cost_per_participant' => 950.00,
        'validity_months' => 24,
        'is_mandatory_compliance' => true,
    ]);

    $service = app(TrainingAndCertificationServiceInterface::class);

    // 1. Assign Training
    $record = $service->assignTraining(new AssignTrainingDTO(
        tenantId: (string) $tenant->id,
        employeeId: (string) $employee->id,
        courseId: (string) $course->id
    ));

    expect($record->status)->toBe('assigned')
        ->and((float) $record->cost_amount)->toBe(950.00);

    Event::assertDispatched(TrainingAssignedEvent::class);
    Event::assertDispatched(TrainingBudgetForecastEvent::class);

    // 2. Complete Training (automatically issues certification and dispatches TrainingCostReadyEvent)
    $completed = $service->completeTraining($record, 96.5);
    expect($completed->status)->toBe('completed')
        ->and($completed->certificate_issued)->toBeTrue();

    Event::assertDispatched(TrainingCostReadyEvent::class);

    // 3. Issue an expiring certification and scan
    $service->issueCertification(
        tenantId: (string) $tenant->id,
        employeeId: (string) $employee->id,
        code: 'CERT-CRANE-2026',
        name: 'Heavy Crane Operator License',
        expiryDate: now()->addDays(12)->toDateString()
    );

    $alerted = $service->scanExpiringCertifications((string) $tenant->id, 30);
    expect($alerted)->toBeGreaterThanOrEqual(1);

    Event::assertDispatched(CertificationExpiringEvent::class);
});
