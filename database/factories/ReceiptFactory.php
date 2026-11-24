<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\ReceiptKind;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Receipt;

/**
 * @extends Factory<Receipt>
 */
final class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'fiscal_year' => 2026,
            'sequential_number' => fake()->unique()->numberBetween(1, 9_999),
            'number' => static fn (array $attributes): string => sprintf('REC-%d-%04d', $attributes['fiscal_year'], $attributes['sequential_number']),
            'kind' => ReceiptKind::Simple,
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'issued_on' => '2026-10-05',
            'amount' => '150.00',
            'description' => 'Quota associativa 2026',
            'holder' => ['name' => fake()->name(), 'payer' => null],
        ];
    }
}
