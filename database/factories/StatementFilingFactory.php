<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\StatementFiling;

/**
 * @extends Factory<StatementFiling>
 */
final class StatementFilingFactory extends Factory
{
    protected $model = StatementFiling::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'year' => 2026,
            'form' => 'cash',
            'status' => 'prepared',
            'snapshot' => ['year' => 2026, 'form' => 'cash', 'lines' => ['A1' => '12000.00', 'B1' => '9500.00']],
            'content_hash' => static fn (array $attributes): string => hash('sha256', (string) json_encode($attributes['snapshot'])),
            'filed_at' => null,
            'filing_reference' => null,
        ];
    }
}
