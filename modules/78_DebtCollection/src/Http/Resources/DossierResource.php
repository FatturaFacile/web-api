<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DebtCollection\Models\Dossier;

/**
 * @mixin Dossier
 */
final class DossierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn (): ?string => $this->customer === null ? null : (trim((string) $this->customer->company_name) !== '' ? (string) $this->customer->company_name : trim($this->customer->first_name.' '.$this->customer->last_name))),
            'currency' => $this->currency,
            'principal' => (string) $this->principal,
            'interest' => (string) $this->interest,
            'fee' => (string) $this->fee,
            'total' => (string) $this->total,
            'status' => $this->status,
            'office' => $this->office_snapshot,
            'receivable_item_ids' => $this->receivable_item_ids,
            'locale' => $this->locale,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'has_bundle' => $this->bundle_path !== null,
        ];
    }
}
