<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

final class PurchaseOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number ?? $this->order_number,
            'supplier_id' => $this->supplier_id,
            'status' => $this->status,
            'order_type' => $this->order_type,
            'order_date' => $this->formatDate($this->order_date),
            'expected_delivery_date' => $this->formatDate($this->expected_delivery_date),
            'currency' => $this->currency,
            'exchange_rate' => (float) ($this->exchange_rate ?? 1.0),
            'net_amount' => (float) ($this->net_amount ?? $this->total_net ?? 0.0),
            'tax_amount' => (float) ($this->tax_amount ?? $this->total_tax ?? 0.0),
            'total_amount' => (float) ($this->total_amount ?? $this->total_gross ?? 0.0),
            'is_budget_encumbered' => (bool) $this->is_budget_encumbered,
            'budget_committed_amount' => (float) ($this->budget_committed_amount ?? 0.0),
            'is_locked' => (bool) $this->is_locked,
            'payment_terms_code' => $this->payment_terms_code,
            'incoterms' => $this->incoterms,
            'shipping_address' => $this->shipping_address,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'lines' => $this->whenLoaded('lines'),
            'receipts' => PurchaseReceiptResource::collection($this->whenLoaded('receipts')),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }

    /**
     * Formatta un valore data in formato YYYY-MM-DD.
     */
    private function formatDate(mixed $date): ?string
    {
        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        if (is_string($date) && trim($date) !== '') {
            return substr($date, 0, 10);
        }

        return null;
    }

    /**
     * Formatta un valore timestamp in standard ISO 8601.
     */
    private function formatDateTime(mixed $dateTime): ?string
    {
        if ($dateTime instanceof CarbonInterface) {
            return $dateTime->toIso8601String();
        }

        if (is_string($dateTime) && trim($dateTime) !== '') {
            return Carbon::parse($dateTime)->toIso8601String();
        }

        return null;
    }
}
