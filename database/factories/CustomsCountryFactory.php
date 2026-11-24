<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Models\CustomsCountry;

/**
 * @extends Factory<CustomsCountry>
 */
final class CustomsCountryFactory extends Factory
{
    protected $model = CustomsCountry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $country = fake()->unique()->country();

        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->lexify('??')),
            'name_de' => $country,
            'name_fr' => $country,
            'name_it' => $country,
            'name_en' => $country,
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
