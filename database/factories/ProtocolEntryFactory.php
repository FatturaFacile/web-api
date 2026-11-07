<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\ProtocolEntry;

/**
 * @extends Factory<ProtocolEntry>
 */
final class ProtocolEntryFactory extends Factory
{
    protected $model = ProtocolEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'fiscal_year' => 2026,
            'sequential_number' => fake()->unique()->numberBetween(1, 99_999),
            'number' => static fn (array $attributes): string => sprintf('PROT%d-%05d', $attributes['fiscal_year'], $attributes['sequential_number']),
            'direction' => 'incoming',
            'registered_on' => '2026-10-20',
            'document_date' => '2026-10-18',
            'correspondent' => 'Comune di '.fake()->city(),
            'subject' => 'Concessione in uso della palestra comunale',
            'category' => 'Enti pubblici',
        ];
    }
}
