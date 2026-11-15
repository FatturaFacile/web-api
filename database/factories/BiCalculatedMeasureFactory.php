<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\BusinessIntelligence\Models\BiCalculatedMeasure;

/**
 * @extends Factory<BiCalculatedMeasure>
 */
final class BiCalculatedMeasureFactory extends Factory
{
    protected $model = BiCalculatedMeasure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'dataset' => 'sales',
            'key' => 'average_ticket_'.fake()->unique()->numberBetween(1, 99999),
            'label' => 'Average ticket',
            'formula' => 'net_amount / document_count',
            'unit' => 'currency',
            'scale' => 2,
        ];
    }
}
