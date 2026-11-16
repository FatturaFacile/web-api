<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Configurator\Models\ProductConfiguration;

/**
 * A saved configuration with the price, margin and approval state of its last evaluation. The snapshot of a converted
 * configuration is part of the detail only.
 *
 * @mixin ProductConfiguration
 */
final class ProductConfigurationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'model_id' => $this->model_id,
            'model' => $this->whenLoaded('configurableModel', fn (): array => ['id' => $this->configurableModel->id, 'code' => $this->configurableModel->code, 'name' => $this->configurableModel->name, 'version' => $this->configurableModel->version]),
            'number' => $this->number,
            'name' => $this->name,
            'contact_id' => $this->contact_id,
            'contact' => $this->whenLoaded('contact', fn (): ?array => $this->contact === null ? null : ['id' => $this->contact->id, 'name' => $this->contact->company_name ?: trim($this->contact->first_name.' '.$this->contact->last_name)]),
            'price_list_id' => $this->price_list_id,
            'quantity' => $this->quantity,
            'discount_percent' => $this->discount_percent,
            'currency' => $this->currency,
            'selections' => $this->selections ?? [],
            'pricing' => $this->pricing,
            'total_net' => $this->total_net,
            'total_cost' => $this->total_cost,
            'margin_percent' => $this->margin_percent,
            'min_margin_percent' => $this->min_margin_percent,
            'requires_approval' => $this->requires_approval,
            'status' => $this->status,
            'model_version' => $this->model_version,
            'created_by' => $this->created_by,
            'validated_at' => $this->validated_at?->toIso8601String(),
            'approval_requested_by' => $this->approval_requested_by,
            'approval_requested_at' => $this->approval_requested_at?->toIso8601String(),
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_notes' => $this->decision_notes,
            'quote_id' => $this->quote_id,
            'quote_number' => $this->whenLoaded('quote', fn (): ?string => $this->quote?->quote_number),
            'converted_at' => $this->converted_at?->toIso8601String(),
            'snapshot' => $this->when($request->routeIs('api.v1.configurator.configurations.show'), $this->snapshot),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
