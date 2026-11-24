<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Sponsor;
use Modules\Associations\Models\SponsorContract;

/**
 * @extends Factory<SponsorContract>
 */
final class SponsorContractFactory extends Factory
{
    protected $model = SponsorContract::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'sponsor_id' => static fn (array $attributes): string => Sponsor::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'title' => 'Sponsorizzazione maglie stagione 2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'amount' => '5000.00',
            'consideration' => 'Logo sulle maglie della prima squadra e cartellonistica a bordo campo',
            'status' => 'active',
        ];
    }
}
