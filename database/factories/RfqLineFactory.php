<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RfqLine>
 */
final class RfqLineFactory extends Factory
{
    protected $model = RfqLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'rfq_id' => RequestForQuotation::factory(),
            'line_number' => 1,
            'custom_item_code' => 'SKU-'.fake()->numerify('####'),
            'description' => fake()->words(3, true),
            'technical_specifications' => fake()->sentence(),
            'quantity' => '10.0000',
            'uom' => 'PCS',
            'target_unit_price' => '25.0000',
            'required_by_date' => now()->addDays(20)->toDateString(),
        ];
    }
}
