<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Models\StatementScheme;

/**
 * @extends Factory<StatementScheme>
 */
final class StatementSchemeFactory extends Factory
{
    protected $model = StatementScheme::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->words(2, true),
            'statement_type' => 'income_statement',
        ];
    }
}
