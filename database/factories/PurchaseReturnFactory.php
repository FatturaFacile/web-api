<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReturn;
use Modules\Procurement\Models\Supplier;

/**
 * @extends Factory<PurchaseReturn>
 */
class PurchaseReturnFactory extends Factory
{
    protected $model = PurchaseReturn::class;

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
        $order = PurchaseOrder::first() ?? PurchaseOrder::factory()->create(['tenant_id' => $tenantId]);
        $supplier = Supplier::first() ?? Supplier::factory()->create(['tenant_id' => $tenantId]);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'purchase_order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'return_number' => 'RTV-'.fake()->unique()->numerify('####'),
            'return_date' => now()->toDateString(),
            'returned_by_user_id' => $user?->id,
            'reason' => 'Defective quality out of spec',
            'status' => 'completed',
        ];
    }
}
