<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\SalesOrderHold;

/**
 * @mixin SalesOrder
 */
class SalesOrderResource extends JsonResource
{
    /**
     * Every column of the order as stored (decimals as strings, dates as `Y-m-d`, timestamps as `Y-m-d H:i:s`) plus the
     * contact summary, the active credit holds and, when loaded, the lines.
     *
     * @return array{id: string, tenant_id: string|null, contact_id: string, quote_id: string|null, order_number: string, date: string, due_date: string|null, currency: string, total_net: string, total_tax: string, total_gross: string, status: string, notes: string|null, created_at: string|null, updated_at: string|null, subtotal: string, discount_amount: string, discount_policy_id: string|null, price_list_id: string|null, customer_agreement_id: string|null, sales_rep_id: string|null, workflow_instance_id: string|null, credit_status: string, credit_hold_reason: string|null, inventory_status: string, fulfillment_status: string, billing_status: string, payment_terms: string|null, shipping_method: string|null, shipping_address: string|null, billing_address: string|null, confirmed_at: string|null, created_by: string|null, approved_by: string|null, approved_at: string|null, contact?: array{id: string, name: string}, credit_hold_id: string|null, holds: list<array{id: string, status: string, reason: string, created_at: string|null}>, items?: list<array{id: string, sales_order_id: string, product_id: string|null, description: string, quantity: string, unit_price: string, tax_rate: string, discount_percentage: string, line_total: string, created_at: string|null, updated_at: string|null, quantity_delivered: string, tenant_id: string|null, quantity_reserved: string, quantity_invoiced: string, tax_code_id: string|null, cost_price: string, uom: string, notes: string|null, deleted_at: string|null}>}
     *
     * @phpstan-return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activeHolds = $this->activeHolds();

        return array_merge(
            collect($this->resource->getAttributes())->except(['deleted_at', 'signature_data'])->all(),
            [
                'contact_id' => $this->contact_id,
                'contact' => $this->whenLoaded('contact', fn () => ['id' => $this->contact->id, 'name' => $this->contact->company_name ?: trim($this->contact->first_name.' '.$this->contact->last_name)]),
                'credit_hold_id' => $activeHolds->first()?->id,
                'holds' => $activeHolds->map(static fn (SalesOrderHold $hold): array => [
                    'id' => (string) $hold->id,
                    'status' => $hold->status,
                    'reason' => $hold->hold_reason,
                    'created_at' => $hold->created_at?->toISOString(),
                ])->values()->all(),
                'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => $item->toArray())->all()),
            ]
        );
    }

    /**
     * The active holds of the order: only an order on hold can have one (a hold moves the order to on_hold and its
     * release moves it back), so other orders are answered without a query unless the relation was eager loaded.
     *
     * @return Collection<int, SalesOrderHold>
     */
    private function activeHolds(): Collection
    {
        if ($this->resource->relationLoaded('holds')) {
            return $this->resource->holds->where('status', 'active')->values();
        }

        if ($this->status !== 'on_hold') {
            return new Collection;
        }

        return $this->resource->holds()->where('status', 'active')->orderBy('placed_at')->get();
    }
}
