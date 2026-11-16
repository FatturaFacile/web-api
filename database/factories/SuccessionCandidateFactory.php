<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TalentLearning\Models\SuccessionCandidate;
use Modules\TalentLearning\Models\SuccessionPosition;

/**
 * @extends Factory<SuccessionCandidate>
 */
final class SuccessionCandidateFactory extends Factory
{
    protected $model = SuccessionCandidate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'succession_position_id' => SuccessionPosition::factory(),
            'employee_id' => (string) Str::uuid7(),
            'readiness' => SuccessionCandidate::ReadyNow,
            'risk_of_loss' => 'low',
            'potential' => 'high',
            'ranking' => 1,
        ];
    }
}
