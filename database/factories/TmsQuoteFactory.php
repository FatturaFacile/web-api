<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Transportation\Models\TmsQuote;

/**
 * @extends Factory<TmsQuote>
 */
final class TmsQuoteFactory extends Factory
{
    protected $model = TmsQuote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'ship_date' => '2027-01-15',
            'weight_kg' => '100.000',
            'currency' => 'EUR',
            'status' => 'open',
        ];
    }
}
