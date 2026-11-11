<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Models\CustomsStatisticalKey;

/**
 * @extends Factory<CustomsStatisticalKey>
 */
final class CustomsStatisticalKeyFactory extends Factory
{
    protected $model = CustomsStatisticalKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'tariff_code' => fake()->unique()->numerify('####.####'),
            'code' => fake()->numerify('9##'),
            'name_de' => 'Andere',
            'name_fr' => 'Autres',
            'name_it' => 'Altri',
            'name_en' => 'Other',
            'valid_from' => '2026-01-01',
            'is_active' => true,
            'source' => 'tenant',
        ];
    }

    /**
     * Official BAZG row shared by every company and read-only for them.
     */
    public function generic(): self
    {
        return $this->state(['tenant_id' => null, 'source' => 'bazg_edec'])->afterMaking(static fn ($model) => $model->declareGlobal());
    }
}
