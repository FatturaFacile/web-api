<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Lex398Entry;

/**
 * @extends Factory<Lex398Entry>
 */
final class Lex398EntryFactory extends Factory
{
    protected $model = Lex398Entry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'entry_date' => fake()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'document_number' => 'FT-2026-'.fake()->unique()->numerify('#####'),
            'counterparty' => fake()->company(),
            'description' => 'Sponsorizzazione evento sportivo',
            'taxable_amount' => '1000.00',
            'vat_rate' => '22.00',
            'vat_amount' => '220.00',
            'total_amount' => '1220.00',
            'source_type' => 'manual',
            'source_id' => null,
        ];
    }
}
