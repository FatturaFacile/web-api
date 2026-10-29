<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierCategory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'country' => 'IT',
            'default_currency' => 'EUR',
        ])->id;

        $user = User::first();
        if (! $user) {
            $user = User::create([
                'id' => (string) Str::uuid(),
                'name' => fake()->name(),
                'email' => fake()->unique()->safeEmail(),
                'password' => bcrypt('password'),
            ]);
        }

        $supplierId = (string) Str::uuid();

        // Ensure linked contact exists to satisfy foreign key constraints
        $contact = Contact::firstOrCreate(
            ['id' => $supplierId],
            [
                'tenant_id' => $tenantId,
                'type' => 'supplier',
                'company_name' => fake()->company(),
                'country' => 'IT',
                'email' => fake()->companyEmail(),
                'phone' => fake()->phoneNumber(),
            ]
        );

        $category = SupplierCategory::first() ?? SupplierCategory::factory()->create(['tenant_id' => $tenantId]);

        return [
            'id' => $supplierId,
            'tenant_id' => $tenantId,
            'contact_id' => $contact->id,
            'supplier_category_id' => $category->id,
            'supplier_code' => 'SUP-'.fake()->unique()->numerify('####'),
            'company_name' => fake()->company().' '.fake()->companySuffix(),
            'vat_number' => 'IT'.fake()->numerify('###########'),
            'fiscal_code' => fake()->numerify('###########'),
            'currency' => fake()->randomElement(['EUR', 'CHF', 'USD']),
            'payment_terms_code' => fake()->randomElement(['NET30', 'NET60', 'DAP']),
            'incoterms' => fake()->randomElement(['DAP', 'FCA', 'EXW', 'CIP']),
            'status' => 'active',
            'is_approved_vendor' => fake()->boolean(80),
            'credit_limit' => fake()->randomFloat(2, 5000, 150000),
            'rating' => fake()->randomElement(['A', 'B', 'C']),
            'is_locked' => false,
            'created_by' => $user->id,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_approved_vendor' => true,
            'status' => 'active',
            'rating' => 'A',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_approved_vendor' => false,
            'status' => 'pending_approval',
            'rating' => 'B',
        ]);
    }

    public function highCredit(): static
    {
        return $this->state(fn (array $attributes) => [
            'credit_limit' => 250000.00,
            'rating' => 'A',
            'is_approved_vendor' => true,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
            'is_approved_vendor' => false,
        ]);
    }
}
