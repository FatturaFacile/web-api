<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\ImportBatch;

/**
 * @extends Factory<ImportBatch>
 */
final class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'original_name' => 'soci_'.fake()->numberBetween(2025, 2027).'.csv',
            'status' => ImportBatch::Preview,
            'rows' => [
                [
                    'line' => 2,
                    'data' => ['first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'email' => fake()->safeEmail()],
                    'errors' => [],
                    'duplicate' => null,
                ],
            ],
            'summary' => ['total' => 1, 'valid' => 1, 'invalid' => 0, 'duplicates' => 0],
            'committed_at' => null,
        ];
    }
}
