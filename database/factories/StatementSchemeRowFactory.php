<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Models\StatementScheme;
use Modules\GeneralLedger\Models\StatementSchemeRow;

/**
 * @extends Factory<StatementSchemeRow>
 */
final class StatementSchemeRowFactory extends Factory
{
    protected $model = StatementSchemeRow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheme_id' => StatementScheme::factory(),
            'tenant_id' => fn (array $attributes): string => (string) StatementScheme::withoutGlobalScopes()->findOrFail($attributes['scheme_id'])->tenant_id,
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'label' => $this->faker->words(2, true),
            'row_type' => 'accounts',
            'sign' => 1,
            'account_prefixes' => ['4'],
            'formula' => [],
        ];
    }
}
