<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\LeaveType;

/**
 * An absence type as the employee self-service shows it: the labels and flags needed to present balances and requests,
 * without the entitlement and carry-over rules of the company.
 *
 * @mixin LeaveType
 */
final class LeaveTypeSelfServiceResource extends JsonResource
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
            'category' => $this->category,
            'is_paid' => (bool) $this->is_paid,
            'requires_approval' => (bool) $this->requires_approval,
            'requires_attachment' => (bool) $this->requires_attachment,
            'is_active' => (bool) $this->is_active,
            'translations' => $this->translations,
        ];
    }
}
