<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DownPayments\Models\DownPaymentChain;

/**
 * A down-payment chain as the list shows it: the source, the customer and the totals.
 *
 * @mixin DownPaymentChain
 */
class ChainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'chain_number' => $this->chain_number,
            'source_type' => $this->source_type,
            'source_id' => (string) $this->source_id,
            'source_number' => $this->source_number,
            'sales_order_id' => $this->sales_order_id,
            'customer_id' => (string) $this->customer_id,
            'customer_name' => $this->customer_name,
            'currency' => $this->currency,
            'jurisdiction' => $this->jurisdiction,
            'status' => $this->status,
            'total_contract_net' => (string) $this->total_contract_net,
            'total_contract_vat' => (string) $this->total_contract_vat,
            'total_contract_gross' => (string) $this->total_contract_gross,
            'total_earnest_deposits_held' => (string) $this->total_earnest_deposits_held,
            'total_security_deposits_held' => (string) $this->total_security_deposits_held,
            'total_advance_invoiced_net' => (string) $this->total_advance_invoiced_net,
            'total_advance_invoiced_vat' => (string) $this->total_advance_invoiced_vat,
            'total_advance_invoiced_gross' => (string) $this->total_advance_invoiced_gross,
            'total_credited_advances_gross' => (string) $this->total_credited_advances_gross,
            'residual_order_gross' => (string) $this->residual_order_gross,
            'final_invoice_id' => $this->final_invoice_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
