<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SupplierPortal\Models\QualificationAssessment;
use Modules\SupplierPortal\Models\QualificationCriterion;
use Modules\SupplierPortal\Models\QualificationScore;

/**
 * @extends Factory<QualificationScore>
 */
final class QualificationScoreFactory extends Factory
{
    protected $model = QualificationScore::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'assessment_id' => QualificationAssessment::factory(),
            'criterion_id' => QualificationCriterion::factory(),
            'score' => '4.00',
        ];
    }
}
