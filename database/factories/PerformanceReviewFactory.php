<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\PerformanceReview;

/**
 * @extends Factory<PerformanceReview>
 */
class PerformanceReviewFactory extends Factory
{
    protected $model = PerformanceReview::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'employee_id' => function (array $attrs) {
                return Employee::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'reviewer_employee_id' => null,
            'review_number' => 'PR-'.strtoupper(Str::random(8)),
            'review_period' => date('Y').'-ANNUAL',
            'review_type' => 'annual',
            'review_date' => date('Y-m-d'),
            'overall_rating' => 4.50,
            'goals_score' => 4.60,
            'competencies_score' => 4.35,
            'status' => 'submitted',
            'promotion_recommended' => true,
            'recommended_salary_increase_percent' => 8.00,
            'successor_readiness' => 'ready_now',
            'is_locked' => false,
            'goals' => [
                ['goal' => 'Deliver Zero-Downtime ERP Migration', 'score' => 4.8],
            ],
            'competencies' => [
                ['competency' => 'Technical Leadership', 'score' => 4.5],
            ],
            'strengths' => 'Exceptional delivery across all enterprise milestones.',
            'improvement_areas' => 'Delegate routine operational reviews earlier.',
        ];
    }
}
