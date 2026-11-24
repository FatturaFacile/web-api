<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Donation;
use Modules\Associations\Models\DonationReceipt;

/**
 * @extends Factory<DonationReceipt>
 */
final class DonationReceiptFactory extends Factory
{
    protected $model = DonationReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequentialNumber = fake()->unique()->numberBetween(1, 99999);

        return [
            'tenant_id' => Tenant::factory(),
            'donation_id' => static fn (array $attributes): string => Donation::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'number' => sprintf('DON-2026-%04d', $sequentialNumber),
            'fiscal_year' => 2026,
            'sequential_number' => $sequentialNumber,
            'issued_on' => '2026-11-10',
            'amount' => '150.00',
            'holder' => [
                'name' => fake()->firstName().' '.fake()->lastName(),
                'tax_code' => strtoupper(fake()->bothify('??????##?##?###?')),
                'address' => 'Via Roma 12, 20121 Milano (MI)',
            ],
        ];
    }
}
