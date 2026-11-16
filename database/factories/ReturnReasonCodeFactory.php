<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Returns\Models\ReturnReasonCode;

/**
 * @extends Factory<ReturnReasonCode>
 */
final class ReturnReasonCodeFactory extends Factory
{
    protected $model = ReturnReasonCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'reason_'.Str::lower(Str::random(6)),
            'label' => fake()->words(3, true),
            'is_defect' => false,
            'is_active' => true,
        ];
    }
}
