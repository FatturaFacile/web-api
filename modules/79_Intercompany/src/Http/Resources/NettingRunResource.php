<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intercompany\Models\NettingItem;
use Modules\Intercompany\Models\NettingRun;

/**
 * @mixin NettingRun
 */
final class NettingRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = NettingItem::withoutGlobalScopes()->where('tenant_id', $this->tenant_id)->where('netting_run_id', $this->id)->orderBy('side')->orderBy('created_at')->get();

        return [
            'id' => (string) $this->id,
            'group_id' => (string) $this->group_id,
            'entity_a_tenant_id' => (string) $this->entity_a_tenant_id,
            'entity_b_tenant_id' => (string) $this->entity_b_tenant_id,
            'as_of_date' => $this->as_of_date->toDateString(),
            'currency' => $this->currency,
            'status' => $this->status,
            'receivable_total' => (string) $this->receivable_total,
            'payable_total' => (string) $this->payable_total,
            'net_amount' => (string) $this->net_amount,
            'net_direction' => $this->net_direction,
            'fx_variance' => (string) $this->fx_variance,
            'amount_variance' => (string) $this->amount_variance,
            'cancel_reason' => $this->cancel_reason,
            'version' => (int) $this->version,
            'items' => NettingItemResource::collection($items)->resolve($request),
        ];
    }
}
