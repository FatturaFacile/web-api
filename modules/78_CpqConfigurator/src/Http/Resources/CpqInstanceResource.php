<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CpqConfigurator\Models\CpqConfiguredInstance;
use Modules\CpqConfigurator\Support\CostRedactor;

/**
 * A configured instance. The industrial cost, the margin and the cost lines of the snapshot are shown only to who may
 * see them (the `viewCosts` ability of the policy).
 *
 * @mixin CpqConfiguredInstance
 */
final class CpqInstanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $costs = $request->user()?->can('viewCosts', CpqConfiguredInstance::class) === true;
        $snapshot = $this->snapshot;

        $data = [
            'id' => $this->id,
            'model_id' => $this->model_id,
            'model_version' => $this->model_version,
            'configuration_hash' => $this->configuration_hash,
            'part_number' => $this->part_number,
            'configuration' => $this->configuration,
            'currency' => $this->currency,
            'list_price' => $this->list_price,
            'selling_price' => $this->selling_price,
            'lead_time_days' => $this->lead_time_days,
            'status' => $this->status,
            'mfg_item_id' => $this->mfg_item_id,
            'mfg_bom_id' => $this->mfg_bom_id,
            'customer_reference' => $this->customer_reference,
            'reuse_count' => $this->reuse_count,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($costs) {
            return $data + ['industrial_cost' => $this->industrial_cost, 'margin_amount' => $this->margin_amount, 'margin_percent' => $this->margin_percent, 'snapshot' => $snapshot];
        }

        return $data + ['snapshot' => CostRedactor::evaluation($snapshot)];
    }
}
