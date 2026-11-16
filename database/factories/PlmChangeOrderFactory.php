<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Plm\Models\PlmChangeOrder;

/**
 * @extends Factory<PlmChangeOrder>
 */
final class PlmChangeOrderFactory extends Factory
{
    protected $model = PlmChangeOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'ECO-'.Str::upper(Str::random(6)),
            'title' => fake()->sentence(4),
            'reason' => fake()->sentence(8),
            'change_type' => 'design',
            'priority' => 'normal',
            'status' => PlmChangeOrder::Draft,
        ];
    }
}
