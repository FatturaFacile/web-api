<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\Loaner;

/**
 * @extends Factory<Loaner>
 */
class LoanerFactory extends Factory
{
    protected $model = Loaner::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'rma_order_id' => DepotRepairRmaOrder::factory(),
            'tenant_id' => fn (array $attributes) => $this->rma($attributes)?->tenant_id,
            'customer_id' => fn (array $attributes) => $this->rma($attributes)?->customer_id,
            'loaner_serial_number' => 'LOAN-'.strtoupper(Str::random(8)),
            'register_type' => 'prestito_uso',
            'issued_at' => now()->subDays(20),
            'status' => 'issued',
            'ddt_causale' => 'prestito_uso',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rma(array $attributes): ?DepotRepairRmaOrder
    {
        return DepotRepairRmaOrder::withoutGlobalScopes()->find($attributes['rma_order_id']);
    }
}
