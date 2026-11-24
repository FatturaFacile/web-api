<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workshop\Models\WorkshopFolder;
use Modules\Workshop\Models\WorkshopFolderLine;

/**
 * @extends Factory<WorkshopFolderLine>
 */
final class WorkshopFolderLineFactory extends Factory
{
    protected $model = WorkshopFolderLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hours = fake()->randomElement([0.5, 1, 1.5, 2, 3]);
        $hourlyRate = 128.0;

        return [
            'tenant_id' => Tenant::factory(),
            'folder_id' => static fn (array $attributes): string => (string) WorkshopFolder::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'line_type' => 'labour',
            'description' => fake()->randomElement(['Sostituzione pastiglie freni anteriori', 'Tagliando 30\'000 km', 'Diagnosi elettronica centralina', 'Sostituzione cinghia distribuzione']),
            'quantity' => number_format($hours, 4, '.', ''),
            'unit_price' => number_format($hourlyRate, 4, '.', ''),
            'discount_percentage' => '0.00',
            'tax_rate' => '8.10',
            'line_total' => number_format($hours * $hourlyRate, 2, '.', ''),
            'sort_order' => 1,
        ];
    }
}
