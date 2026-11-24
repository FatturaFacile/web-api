<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ConstructionTrades\Models\Bid;

/**
 * @extends Factory<Bid>
 */
class TradesBidFactory extends Factory
{
    protected $model = Bid::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contact_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'bid_number' => strtoupper(fake()->unique()->bothify('BID-#####')),
            'title' => 'Tinteggiatura '.fake()->word(),
            'language' => 'it',
            'currency' => 'CHF',
            'tax_rate' => '8.10',
            'status' => 'draft',
        ];
    }
}
