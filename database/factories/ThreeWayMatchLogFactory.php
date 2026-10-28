<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\ThreeWayMatchLog;

/**
 * @extends Factory<ThreeWayMatchLog>
 */
class ThreeWayMatchLogFactory extends Factory
{
    protected $model = ThreeWayMatchLog::class;

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
        $receipt = PurchaseReceipt::first() ?? PurchaseReceipt::factory()->create(['tenant_id' => $tenantId]);

        $amount = (float) $order->total_amount;

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'purchase_order_id' => $order->id,
            'purchase_receipt_id' => $receipt->id,
            'passive_invoice_id' => (string) Str::uuid(),
            'match_status' => 'matched',
            'po_total_amount' => $amount,
            'grn_total_amount' => $amount,
            'invoice_total_amount' => $amount,
            'variance_amount' => 0.0,
            'variance_percentage' => 0.0,
            'discrepancy_details' => ['status' => 'exact_match'],
            'evaluated_at' => now(),
            'approved_by' => $user?->id,
        ];
    }

    public function priceVariance(float $diff = 50.00): static
    {
        return $this->state(fn (array $attributes) => [
            'match_status' => 'price_variance',
            'invoice_total_amount' => $attributes['po_total_amount'] + $diff,
            'variance_amount' => $diff,
            'variance_percentage' => round(($diff / max(1, $attributes['po_total_amount'])) * 100, 2),
            'discrepancy_details' => ['price_mismatch' => "Invoice exceeds PO by {$diff}"],
        ]);
    }

    public function quantityVariance(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_status' => 'quantity_variance',
            'grn_total_amount' => $attributes['po_total_amount'] * 0.8,
            'variance_amount' => $attributes['po_total_amount'] * 0.2,
            'variance_percentage' => 20.0,
            'discrepancy_details' => ['quantity_mismatch' => 'Under-delivered by 20%'],
        ]);
    }

    public function escalated(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_status' => 'escalated_to_workflow',
            'discrepancy_details' => ['escalation' => 'Discrepancy routed to Workflow Engine V2'],
        ]);
    }
}
