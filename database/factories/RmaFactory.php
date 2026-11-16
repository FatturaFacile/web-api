<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Returns\Models\Rma;

/**
 * @extends Factory<Rma>
 */
final class RmaFactory extends Factory
{
    protected $model = Rma::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'rma_number' => 'RMA-'.now()->format('Y').'-'.fake()->unique()->numerify('####'),
            'contact_id' => Contact::factory(),
            'status' => Rma::Requested,
            'reason_code' => 'defective',
            'requested_on' => now()->toDateString(),
            'window_overridden' => false,
        ];
    }
}
