<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Intrastat\Enums\DeclarationStatus;
use Modules\Intrastat\Enums\IntrastatFlow;
use Modules\Intrastat\Enums\Periodicity;
use Modules\Intrastat\Models\IntrastatDeclaration;

/**
 * @extends Factory<IntrastatDeclaration>
 */
final class IntrastatDeclarationFactory extends Factory
{
    protected $model = IntrastatDeclaration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::factory(),
            'flow' => IntrastatFlow::Sales,
            'year' => 2027,
            'periodicity' => Periodicity::Monthly,
            'period' => $this->faker->numberBetween(1, 12),
            'reference_number' => $this->faker->unique()->numberBetween(1, 999_999),
            'content_code' => 0,
            'special_case_code' => 0,
            'obligated_vat_number' => $this->faker->numerify('###########'),
            'status' => DeclarationStatus::Draft,
        ];
    }

    public function purchases(): self
    {
        return $this->state(fn (array $attributes): array => ['flow' => IntrastatFlow::Purchases, 'periodicity' => Periodicity::Monthly]);
    }

    public function quarterly(): self
    {
        return $this->state(fn (array $attributes): array => ['periodicity' => Periodicity::Quarterly, 'period' => $this->faker->numberBetween(1, 4)]);
    }

    public function submitted(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => DeclarationStatus::Submitted, 'submitted_at' => now(), 'protocol_number' => $this->faker->numerify('######')]);
    }
}
