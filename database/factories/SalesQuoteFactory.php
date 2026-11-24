<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesQuote;

/**
 * @extends Factory<SalesQuote>
 */
final class SalesQuoteFactory extends Factory
{
    protected $model = SalesQuote::class;

    public function definition(): array
    {
        $tenantId = (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'contact_id' => Contact::factory(),
            'quote_number' => 'Q-2027-'.$this->faker->numerify('#####'),
            'date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'currency' => 'EUR',
            'subtotal' => 1000.00,
            'discount_amount' => 50.00,
            'total_net' => 950.00,
            'total_tax' => 209.00,
            'total_gross' => 1159.00,
            'status' => 'draft',
            'revision_number' => 1,
            'notes' => 'Factory quote notes',
        ];
    }

    public function accepted(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'accepted',
            'signed_at' => now(),
        ]);
    }

    public function approved(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => (string) Str::uuid7(),
        ]);
    }
}
