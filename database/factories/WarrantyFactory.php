<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Returns\Models\Warranty;

/**
 * @extends Factory<Warranty>
 */
final class WarrantyFactory extends Factory
{
    protected $model = Warranty::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'product_id' => (string) Str::uuid7(),
            'contact_id' => Contact::factory(),
            'serial_number' => 'SN-'.Str::upper(Str::random(8)),
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'coverage_type' => 'full',
            'status' => Warranty::Active,
        ];
    }
}
