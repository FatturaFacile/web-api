<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\ShiftSequence;
use Modules\HumanResources\Models\ShiftType;
use Modules\HumanResources\Services\Shifts\ShiftSequencePattern;

/**
 * @extends Factory<ShiftSequence>
 */
final class ShiftSequenceFactory extends Factory
{
    protected $model = ShiftSequence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Rotazione '.fake()->unique()->numerify('###'),
            'pattern' => ShiftSequencePattern::Rotation,
            'steps' => static function (array $attributes): array {
                $morning = (string) ShiftType::factory()->create(['tenant_id' => $attributes['tenant_id']])->id;
                $afternoon = (string) ShiftType::factory()->create(['tenant_id' => $attributes['tenant_id']])->id;

                return [$morning, $morning, $afternoon, $afternoon, null, null];
            },
            'skip_holidays' => true,
            'is_active' => true,
        ];
    }
}
