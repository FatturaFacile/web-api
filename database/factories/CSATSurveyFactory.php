<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\CSATSurvey;

class CSATSurveyFactory extends Factory
{
    protected $model = CSATSurvey::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'customer_id' => (string) Str::uuid7(),
            'score' => 5,
            'feedback' => fake()->sentence(),
            'sentiment' => 'positive',
            'responded_at' => now(),
        ];
    }
}
