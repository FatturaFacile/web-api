<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    /**
     * Every column of the quote as stored (decimals as strings, dates as `Y-m-d`, timestamps as `Y-m-d H:i:s`) plus the
     * contact summary and, when loaded, the lines. Quote lines have no delivered or invoiced quantities: those exist on
     * sales order lines only.
     *
     * @return array{id: string, tenant_id: string|null, contact_id: string, quote_number: string, date: string, valid_until: string|null, currency: string, total_net: string, total_tax: string, total_gross: string, status: string, notes: string|null, created_at: string|null, updated_at: string|null, custom_text: string|null, signed_at: string|null, signed_by: string|null, subtotal: string, discount_amount: string, discount_policy_id: string|null, price_list_id: string|null, payment_terms: string|null, shipping_terms: string|null, revision_number: int, workflow_instance_id: string|null, created_by: string|null, approved_by: string|null, approved_at: string|null, converted_to_order_id: string|null, revision_of_quote_id: string|null, superseded_at: string|null, contact?: array{id: string, name: string}, items?: list<array{id: string, quote_id: string, product_id: string|null, description: string, quantity: string, unit_price: string, tax_rate: string, discount_percentage: string, line_total: string, created_at: string|null, updated_at: string|null, tenant_id: string|null, tax_code_id: string|null, cost_price: string, margin_percentage: string, uom: string, notes: string|null, deleted_at: string|null}>}
     *
     * @phpstan-return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            collect($this->resource->getAttributes())->except(['deleted_at', 'signature_data'])->all(),
            [
                'contact_id' => $this->contact_id,
                'contact' => $this->whenLoaded('contact', fn () => ['id' => $this->contact->id, 'name' => $this->contact->company_name ?: trim($this->contact->first_name.' '.$this->contact->last_name)]),
                'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => $item->toArray())->all()),
            ]
        );
    }
}
