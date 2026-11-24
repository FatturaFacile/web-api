<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\RibaPresentation;

/**
 * @extends Factory<RibaPresentation>
 */
final class RibaPresentationFactory extends Factory
{
    protected $model = RibaPresentation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'payment_batch_id' => static fn (array $attributes): string => (string) PaymentBatch::create([
                'tenant_id' => $attributes['tenant_id'],
                'batch_reference' => strtoupper(fake()->unique()->bothify('RIBA-2026-####')),
                'payment_method' => 'riba',
                'format' => 'cbi_riba',
                'status' => 'draft',
                'currency' => 'EUR',
                'total_amount' => '2318.00',
                'item_count' => 1,
                'execution_date' => '2026-12-31',
            ])->id,
            'sia_code' => strtoupper(fake()->bothify('?####')),
            'file_name' => null,
            'flat_file' => null,
            'file_sha256' => null,
            'exported_at' => null,
        ];
    }
}
