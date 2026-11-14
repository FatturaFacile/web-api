<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SupplierPortal\Models\DocumentRequirement;

/**
 * A required supplier document type.
 *
 * @mixin DocumentRequirement
 */
final class DocumentRequirementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'country' => $this->country,
            'is_mandatory' => $this->is_mandatory,
            'requires_expiry' => $this->requires_expiry,
            'validity_days' => $this->validity_days,
            'alert_days' => $this->alert_days,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
