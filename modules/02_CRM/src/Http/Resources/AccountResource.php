<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\Account;

/**
 * A CRM account with the master data of its contact embedded under `contact`. `name` is the contact's company name
 * when the account is linked (the account column is only a synchronised copy).
 *
 * @mixin Account
 */
final class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $contact = $this->contact_id !== null ? $this->primaryContact : null;

        return [
            'id' => $this->id,
            'account_number' => $this->account_number,
            'name' => $contact?->company_name ?: $this->name,
            'contact_id' => $this->contact_id,
            'contact' => $contact !== null ? new ContactMasterDataResource($contact) : null,
            'parent_account_id' => $this->parent_account_id,
            'owner_user_id' => $this->owner_user_id,
            'industry' => $this->industry,
            'tier' => $this->tier,
            'status' => $this->status,
            'annual_revenue' => $this->annual_revenue,
            'employee_count' => $this->employee_count,
            'website' => $this->website,
            'customer_health_index' => (float) $this->customer_health_index,
            'churn_risk' => $this->churn_risk,
            'lifecycle_stage' => $this->lifecycle_stage,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'tags' => $this->tags,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
