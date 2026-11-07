<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\SafetyDataSheetVersion;

/**
 * @extends Factory<SafetyDataSheetVersion>
 */
final class SafetyDataSheetVersionFactory extends Factory
{
    protected $model = SafetyDataSheetVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'safety_data_sheet_id' => static fn (array $attributes): string => (string) SafetyDataSheetFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'version_number' => 1,
            'revision_date' => '2026-09-15',
            'hazard_classes' => ['H315', 'H319', 'H412'],
            'signal_word' => 'Attenzione',
            'notes' => null,
            'disk' => 'local',
            'path' => 'safety-data-sheets/'.Str::uuid().'.pdf',
            'original_name' => 'SDS_sgrassatore_industriale_rev1.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(80_000, 900_000),
            'created_by' => null,
        ];
    }
}
