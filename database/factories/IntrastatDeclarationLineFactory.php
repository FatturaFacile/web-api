<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Models\IntrastatDeclarationLine;

/**
 * A fiscal row of section 1 (sales of goods) to a German customer.
 *
 * @extends Factory<IntrastatDeclarationLine>
 */
final class IntrastatDeclarationLineFactory extends Factory
{
    protected $model = IntrastatDeclarationLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'declaration_id' => IntrastatDeclaration::factory(),
            'tenant_id' => fn (array $attributes) => IntrastatDeclaration::query()->withoutGlobalScopes()->whereKey($attributes['declaration_id'])->value('tenant_id'),
            'section' => 1,
            'line_number' => 1,
            'partner_country_code' => 'DE',
            'partner_vat_number' => $this->faker->numerify('#########'),
            'amount_eur' => $this->faker->numberBetween(1, 100_000),
        ];
    }
}
