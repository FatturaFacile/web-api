<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Models\CustomsTariffNumber;

/**
 * @extends Factory<CustomsTariffNumber>
 */
final class CustomsTariffNumberFactory extends Factory
{
    protected $model = CustomsTariffNumber::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => fake()->unique()->numerify('####.####'),
            'name_de' => 'Tragbare automatische Datenverarbeitungsmaschinen',
            'name_fr' => 'Machines automatiques de traitement de l\'information portatives',
            'name_it' => 'Macchine automatiche per l\'elaborazione dell\'informazione, portatili',
            'name_en' => 'Portable automatic data processing machines',
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
