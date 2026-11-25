<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\FranchiseStore;

/**
 * @mixin FranchiseStore
 */
final class StoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'franchisee_tenant_id' => $this->franchisee_tenant_id,
            'linked' => $this->franchisee_tenant_id !== null,
            'contact_id' => $this->contact_id,
            'code' => $this->code,
            'name' => $this->name,
            'store_type' => $this->store_type->value,
            'status' => $this->status->value,
            'country' => $this->country,
            'city' => $this->city,
            'address' => $this->address,
            'postal_code' => $this->postal_code,
            'size_cluster' => $this->size_cluster->value,
            'opened_on' => $this->opened_on?->toDateString(),
            'area_manager_user_id' => $this->area_manager_user_id,
            'vat_number' => $this->vat_number,
            'uid_number' => $this->uid_number,
        ];
    }
}
