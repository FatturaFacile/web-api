<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoicing\Models\DeclarationOfIntent;

/**
 * @extends Factory<DeclarationOfIntent>
 */
final class DeclarationOfIntentFactory extends Factory
{
    protected $model = DeclarationOfIntent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contact_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'direction' => DeclarationOfIntent::RECEIVED,
            'protocol_number' => fake()->unique()->numerify('26#############-######'),
            'receipt_date' => '2026-01-20',
            'year' => 2026,
            'plafond_amount' => '50000.00',
            'consumed_amount' => '0.00',
            'valid_from' => '2026-01-20',
            'valid_to' => '2026-12-31',
            'notes' => null,
        ];
    }
}
