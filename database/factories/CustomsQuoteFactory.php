<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Enums\QuoteStatus;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Models\CustomsQuote;
use Modules\Customs\Models\CustomsTariffGrid;

/**
 * @extends Factory<CustomsQuote>
 */
final class CustomsQuoteFactory extends Factory
{
    protected $model = CustomsQuote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'dossier_id' => static fn (array $attributes): string => (string) CustomsDossier::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'quote_number' => strtoupper(fake()->unique()->bothify('OFF-2026-#####')),
            'status' => QuoteStatus::Draft,
            'is_spot' => false,
            'tariff_grid_id' => static fn (array $attributes): string => (string) CustomsTariffGrid::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'discount_percent' => '0.00',
            'total_amount' => number_format(fake()->randomFloat(2, 250, 5000), 2, '.', ''),
            'currency' => 'CHF',
            'valid_until' => fake()->dateTimeBetween('2026-06-01', '2027-06-30')->format('Y-m-d'),
        ];
    }

    /**
     * A one-off price agreed outside the tariff grids.
     */
    public function spot(): self
    {
        return $this->state(['is_spot' => true, 'tariff_grid_id' => null]);
    }
}
